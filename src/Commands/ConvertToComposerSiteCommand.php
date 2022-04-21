<?php

namespace Pantheon\TerminusConversionTools\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\TerminusConversionTools\Commands\Traits\ConversionCommandsTrait;
use Pantheon\TerminusConversionTools\Commands\Traits\MigrateComposerJsonTrait;
use Pantheon\TerminusConversionTools\Commands\Traits\DrushCommandsTrait;
use Pantheon\TerminusConversionTools\Utils\DrupalProjects;
use Pantheon\TerminusConversionTools\Utils\Files;
use Pantheon\TerminusConversionTools\Utils\Git;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Class ConvertToComposerSiteCommand.
 */
class ConvertToComposerSiteCommand extends TerminusCommand implements SiteAwareInterface
{
    use ConversionCommandsTrait;
    use MigrateComposerJsonTrait;
    use DrushCommandsTrait;

    private const TARGET_GIT_BRANCH = 'conversion';
    private const TARGET_UPSTREAM_GIT_REMOTE_URL = 'https://github.com/pantheon-upstreams/drupal-recommended.git';
    private const MODULES_SUBDIR = 'modules';
    private const THEMES_SUBDIR = 'themes';
    private const WEB_ROOT = 'web';

    /**
     * @var bool
     */
    private bool $isWebRootSite;

    /**
     * @var \Pantheon\TerminusConversionTools\Utils\DrupalProjects
     */
    private DrupalProjects $drupalProjects;

    /**
     * Converts a standard Drupal site into a Drupal site managed by Composer.
     *
     * @command conversion:composer
     *
     * @option branch The target branch name for multidev env.
     * @option dry-run Skip creating multidev and pushing composerify branch.
     *
     * @param string $site_id
     *   The name or UUID of a site to operate on
     * @param array $options
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Composer\ComposerException
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Pantheon\Terminus\Exceptions\TerminusNotFoundException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    public function convert(
        string $site_id,
        array $options = [
            'branch' => self::TARGET_GIT_BRANCH,
            'dry-run' => false,
            'run-updb' => true,
            'run-cr' => true,
        ]
    ): void {
        $this->setSite($site_id);
        $this->setBranch($options['branch']);

        if (!$this->site()->getFramework()->isDrupal8Framework()) {
            throw new TerminusException(
                'The site {site_name} is not a Drupal 8 based site.',
                ['site_name' => $this->site()->getName()]
            );
        }

        if (!in_array(
            $this->site()->getUpstream()->get('machine_name'),
            $this->getSupportedSourceUpstreamIds(),
            true
        )) {
            throw new TerminusException(
                'Unsupported upstream {upstream}. Supported upstreams are: {supported_upstreams}.',
                [
                    'upstream' => $this->site()->getUpstream()->get('machine_name'),
                    'supported_upstreams' => implode(', ', $this->getSupportedSourceUpstreamIds())
                ]
            );
        }

        $defaultConfigFilesDir = Files::buildPath($this->getDrupalAbsolutePath(), 'sites', 'default', 'config');
        $isDefaultConfigFilesExist = is_dir($defaultConfigFilesDir);

        $this->drupalProjects = new DrupalProjects($this->getDrupalAbsolutePath());
        $this->setGit($this->getLocalSitePath());
        $sourceComposerJson = $this->getComposerJson();

        $contribProjects = $this->getContribDrupalProjects();
        $libraryProjects = $this->getLibraries();
        $customProjectsDirs = $this->getCustomProjectsDirectories();

        $this->createLocalGitBranchFromRemote(self::TARGET_UPSTREAM_GIT_REMOTE_URL);
        if ($isDefaultConfigFilesExist) {
            $this->copyConfigurationFiles();
        } else {
            $this->log()->notice(
                sprintf(
                    'Skipped copying configuration files: default configuration files directory (%s) not found.',
                    $defaultConfigFilesDir
                )
            );
        }
        $this->copyPantheonYml();
        $this->copyCustomProjects($customProjectsDirs);
        $this->copySettingsPhp();

        $this->migrateComposerJson(
            $sourceComposerJson,
            $this->getLocalSitePath(),
            $contribProjects,
            $libraryProjects,
            Files::buildPath($this->drupalProjects->getSiteRootPath(), 'libraries-backup'),
        );

        if (!$options['dry-run']) {
            $this->pushTargetBranch();
            $this->addCommitToTriggerBuild();
            if ($options['run-updb'] || $options['run-cr']) {
                $this->waitForSyncCodeWorkflow($options['branch']);
                if ($options['run-updb']) {
                    $this->runDrushCommand('updb -y');
                }
                if ($options['run-cr']) {
                    $this->runDrushCommand('cr');
                }
            }
        } else {
            $this->log()->warning('Push to multidev has skipped');
        }

        $this->log()->notice('Done!');
    }

    /**
     * Returns TRUE if the site is a webroot-based site.
     *
     * @return bool
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function isWebRootSite(): bool
    {
        if (isset($this->isWebRootSite)) {
            return $this->isWebRootSite;
        }

        $pantheonYmlContent = Yaml::parseFile(Files::buildPath($this->getLocalSitePath(), 'pantheon.yml'));
        $this->isWebRootSite = $pantheonYmlContent['web_docroot'] ?? false;

        return $this->isWebRootSite;
    }

    /**
     * Returns the absolute site's Drupal core installation directory.
     *
     * @return string
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function getDrupalAbsolutePath(): string
    {
        return $this->isWebRootSite()
            ? Files::buildPath($this->getLocalSitePath(), self::WEB_ROOT)
            : $this->getLocalSitePath();
    }

    /**
     * Returns webroot-aware relative path.
     *
     * @param string ...$parts
     *
     * @return string
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function getWebRootAwareRelativePath(string ...$parts): string
    {
        return $this->isWebRootSite()
            ? Files::buildPath(self::WEB_ROOT, ...$parts)
            : Files::buildPath(...$parts);
    }

    /**
     * Detects and returns the list of Drupal libraries.
     *
     * @return array
     *   The list of Composer package names.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function getLibraries(): array
    {
        $this->log()->notice(sprintf('Detecting libraries in "%s"...', $this->getDrupalAbsolutePath()));
        $projects = $this->drupalProjects->getLibraries();

        if (0 === count($projects)) {
            $this->log()->notice(sprintf('No libraries were detected in "%s"', $this->getDrupalAbsolutePath()));

            return [];
        }

        $projectsInline = array_map(
            fn($project) => $project,
            $projects
        );
        $this->log()->notice(
            sprintf(
                '%d libraries are detected: %s',
                count($projects),
                implode(', ', $projectsInline)
            )
        );

        return $projects;
    }

    /**
     * Detects and returns the list of contrib Drupal projects (modules and themes).
     *
     * @return array
     *   The list of contrib modules and themes where:
     *     "name" is a module/theme name;
     *     "version" is a module/theme version.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function getContribDrupalProjects(): array
    {
        $this->log()->notice(
            sprintf('Detecting contrib modules and themes in "%s"...', $this->getDrupalAbsolutePath())
        );
        $projects = $this->drupalProjects->getContribProjects();
        if (0 === count($projects)) {
            $this->log()->notice(
                sprintf('No contrib modules or themes were detected in "%s"', $this->getDrupalAbsolutePath())
            );

            return [];
        }

        $projectsInline = array_map(
            fn($project) => sprintf('%s (%s)', $project['name'], $project['version']),
            $projects
        );
        $this->log()->notice(
            sprintf(
                '%d contrib modules and/or themes are detected: %s',
                count($projects),
                implode(', ', $projectsInline)
            )
        );

        return $projects;
    }

    /**
     * Returns the list directories of custom projects.
     *
     * @return array
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function getCustomProjectsDirectories(): array
    {
        $this->log()->notice(
            sprintf('Detecting custom projects (modules and themes) in "%s"...', $this->getDrupalAbsolutePath())
        );
        $customModuleDirs = $this->drupalProjects->getCustomModuleDirectories();
        foreach ($customModuleDirs as $path) {
            $this->log()->notice(sprintf('Custom modules found in "%s"', $path));
        }

        $customThemeDirs = $this->drupalProjects->getCustomThemeDirectories();
        foreach ($customThemeDirs as $path) {
            $this->log()->notice(sprintf('Custom themes found in "%s"', $path));
        }

        return [
            self::MODULES_SUBDIR => $customModuleDirs,
            self::THEMES_SUBDIR => $customThemeDirs,
        ];
    }

    /**
     * Copies configuration files.
     */
    private function copyConfigurationFiles(): void
    {
        $this->log()->notice('Copying configuration files...');
        try {
            $sourceRelativePath = $this->getWebRootAwareRelativePath('sites', 'default', 'config');
            $this->getGit()->checkout(Git::DEFAULT_BRANCH, $sourceRelativePath);
            $sourceAbsolutePath = Files::buildPath($this->getDrupalAbsolutePath(), 'sites', 'default', 'config');
            $destinationPath = Files::buildPath($this->getDrupalAbsolutePath(), 'config');
            $this->getGit()->move(sprintf('%s%s*', $sourceAbsolutePath, DIRECTORY_SEPARATOR), $destinationPath);

            $htaccessFile = $this->getWebRootAwareRelativePath('sites', 'default', 'config', '.htaccess');
            $this->getGit()->remove('-f', $htaccessFile);

            if ($this->getGit()->isAnythingToCommit()) {
                $this->getGit()->commit('Pull in configuration from default git branch');
            } else {
                $this->log()->notice('No configuration files found');
            }
        } catch (Throwable $t) {
            $this->log()->warning(sprintf('Failed copying configuration files: %s', $t->getMessage()));
        }
    }

    /**
     * Copies pantheon.yml file and sets the "build_step" flag.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function copyPantheonYml(): void
    {
        $this->log()->notice('Copying pantheon.yml file...');
        $this->getGit()->checkout(Git::DEFAULT_BRANCH, 'pantheon.yml');
        $this->getGit()->commit('Copy pantheon.yml');

        $path = Files::buildPath($this->getLocalSitePath(), 'pantheon.yml');
        $pantheonYmlContent = Yaml::parseFile($path);
        if (isset($pantheonYmlContent['build_step']) && true === $pantheonYmlContent['build_step']) {
            return;
        }

        $pantheonYmlContent['build_step'] = true;
        $pantheonYmlFile = fopen($path, 'wa+');
        fwrite($pantheonYmlFile, Yaml::dump($pantheonYmlContent, 2, 2));
        fclose($pantheonYmlFile);

        $this->getGit()->commit('Add build_step:true to pantheon.yml');
    }

    /**
     * Copies custom projects (modules and themes).
     *
     * @param array $customProjectsDirs
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function copyCustomProjects(array $customProjectsDirs): void
    {
        if (0 === count(array_filter($customProjectsDirs))) {
            $this->log()->notice('No custom projects (modules and themes) to copy');

            return;
        }

        $this->log()->notice('Copying custom modules and themes...');
        foreach ($customProjectsDirs as $subDir => $dirs) {
            foreach ($dirs as $relativePath) {
                $relativePath = $this->getWebRootAwareRelativePath($relativePath);
                try {
                    $this->getGit()->checkout(Git::DEFAULT_BRANCH, $relativePath);
                    $targetPath = Files::buildPath(self::WEB_ROOT, $subDir, 'custom');

                    if (!is_dir(Files::buildPath($this->getLocalSitePath(), $targetPath))) {
                        mkdir(Files::buildPath($this->getLocalSitePath(), $targetPath), 0755, true);
                    }

                    if (!$this->isWebRootSite()) {
                        $this->getGit()->move(sprintf('%s%s*', $relativePath, DIRECTORY_SEPARATOR), $targetPath);
                    }

                    if ($this->getGit()->isAnythingToCommit()) {
                        $this->getGit()->commit(sprintf('Copy custom %s from %s', $subDir, $relativePath));
                        $this->log()->notice(sprintf('Copied custom %s from %s', $subDir, $relativePath));
                    }
                } catch (Throwable $t) {
                    $this->log()->warning(
                        sprintf('Failed copying custom %s from %s: %s', $subDir, $relativePath, $t->getMessage())
                    );
                }
            }
        }
    }

    /**
     * Copies settings.php file.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function copySettingsPhp(): void
    {
        $this->log()->notice('Copying settings.php file...');
        $settingsPhpFilePath = $this->getWebRootAwareRelativePath('sites', 'default', 'settings.php');
        $this->getGit()->checkout(Git::DEFAULT_BRANCH, $settingsPhpFilePath);

        if (!$this->isWebRootSite()) {
            $this->getGit()->move(
                $settingsPhpFilePath,
                Files::buildPath(self::WEB_ROOT, 'sites', 'default', 'settings.php'),
                '-f',
            );
        }

        if ($this->getGit()->isAnythingToCommit()) {
            $this->getGit()->commit('Copy settings.php');
            $this->log()->notice('settings.php file has been copied.');
        }
    }
}
