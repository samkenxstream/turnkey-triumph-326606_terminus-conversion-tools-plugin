<?php

namespace Pantheon\TerminusConversionTools\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\TerminusConversionTools\Commands\Traits\ConversionCommandsTrait;
use Pantheon\TerminusConversionTools\Utils\Files;
use Composer\Semver\Comparator;
use Throwable;

/**
 * Class AdviseCommand.
 */
class AdviseCommand extends TerminusCommand implements SiteAwareInterface
{
    use ConversionCommandsTrait;

    private const DROPS_8_UPSTREAM_ID = 'drupal8';
    private const DROPS_8_GIT_REMOTE_URL = 'https://github.com/pantheon-systems/drops-8.git';

    private const DRUPAL_PROJECT_UPSTREAM_ID = 'drupal9';

    private const EMPTY_UPSTREAM_ID = 'empty';

    private const DRUPAL_RECOMMENDED_UPSTREAM_ID = 'drupal-recommended';
    private const DRUPAL_RECOMMENDED_GIT_REMOTE_URL = 'https://github.com/pantheon-upstreams/drupal-recommended.git';

    /**
     * Analyze the current state of the site and give advice on the next steps.
     *
     * @command conversion:advise
     *
     * @option skip-upgrade-checks Skip upgrade checks during this command run.
     *
     * @param string $siteId
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    public function advise(string $siteId, array $options = ['skip-upgrade-checks' => false]): void
    {
        $this->setSite($siteId);
        $upstreamId = $this->site()->getUpstream()->get('machine_name');
        $this->writeln(
            sprintf(
<<<EOD
The site %s was created from the upstream:
    %s (%s)
EOD,
                $this->site()->getName(),
                $this->site()->getUpstream()->get('label'),
                $upstreamId,
            )
        );


        if (self::DRUPAL_RECOMMENDED_UPSTREAM_ID === $upstreamId) {
            $this->writeln('No conversion is necessary.');
            return;
        }

        if (!$options['skip-upgrade-checks']) {
            $env = $this->site()->getEnvironments()->get('dev');
            $status = $env->getUpstreamStatus();
            if ($status->hasUpdates() || $status->hasComposerUpdates()) {
                $this->writeln("Notice: The site has upstream updates to be applied. Run `{$this->getTerminusExecutable()} upstream:updates:apply $siteId` to apply them.");
            }
            $phpVersion = $env->getPHPVersion();
            if (Comparator::lessThan($phpVersion, '7.4')) {
                $this->writeln("Notice: The site's PHP version is $phpVersion. Upgrade to PHP 7.4 or higher.");
            }
        }

        if (self::DROPS_8_UPSTREAM_ID === $upstreamId) {
            $this->adviseOnDrops8();

            return;
        }

        if (self::DRUPAL_PROJECT_UPSTREAM_ID === $upstreamId) {
            $this->adviseOnDrupalProject();

            return;
        }

        if (self::EMPTY_UPSTREAM_ID === $upstreamId) {
            $this->adviseOnEmpty();

            return;
        }
    }

    /**
     * Prints advice related to "drops-8" upstream.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function adviseOnDrops8(): void
    {
        $localPath = $this->getLocalSitePath(false);
        $this->setGit($localPath);
        $this->getGit()->addRemote(self::DROPS_8_GIT_REMOTE_URL, self::DROPS_8_UPSTREAM_ID);
        $this->getGit()->fetch(self::DROPS_8_UPSTREAM_ID);

        try {
            $composerJsonRequireExtraPackages = [];
            $composerJsonContents = file_get_contents(Files::buildPath($localPath, 'composer.json'));
            $composerJsonRequireExtraPackages = array_keys(array_filter(
                json_decode($composerJsonContents, true)['require'],
                fn($package) => 'composer/installers' !== $package && false === strpos($package, 'drupal/core-'),
                ARRAY_FILTER_USE_KEY
            ));
        } catch (Throwable $t) {
            $this->log()->error(
                sprintf('Failed composer.json analysis: %s', $t->getMessage())
            );
        }

        if (0 < count($composerJsonRequireExtraPackages)) {
            $this->output()->writeln(
                <<<EOD
NOTICE: Although the site's upstream is not Composer-managed, Composer was used
to add modules to the site. Doing this results in a working site, but might
cause difficulties when applying upstream updates in the future. Following these
conversion steps should automatically repair this situation.\n
EOD
            );

            $this->log()->notice(
                sprintf(
                    "The packages you installed are:\n%s.\n",
                    implode(', ', $composerJsonRequireExtraPackages)
                )
            );
        } else {
            $this->output()->writeln('Standard drupal 8 site.');
        }

        $this->output()->writeln(
            <<<EOD
Advice: We recommend that this site be converted to a Composer-managed upstream:
    Drupal Recommended (drupal-recommended)
This process may be done manually by following the instructions in the guide:
    https://pantheon.io/docs/guides/composer-convert
An automated process to convert this site is available. To begin, run:
    `{$this->getTerminusExecutable()} conversion:composer {$this->site()->getName()}`)
This command will create a new multidev named “conversion” that will contain a copy of your site converted to a Composer-managed site. Once you have tested this environment, the follow-on steps will be:
    {$this->getTerminusExecutable()} conversion:release-to-master {$this->site()->getName()}
    {$this->getTerminusExecutable()} site:upstream:set {$this->site()->getName()} drupal-recommended
You may run the conversion:advise command again to check your progress and see the next steps again.
EOD
        );
    }

    /**
     * Prints advice related to "drupal-project" upstream.
     */
    private function adviseOnDrupalProject(): void
    {
        $this->writeln('This site is using the upstream pantheon-systems/drupal-project, which was the default upstream prior to November 30, 2021.');
        $this->output()->writeln(
            <<<EOD
Advice: convert the site to use "drupal-recommended" Pantheon Upstream by using `conversion:drupal-recommended`
Terminus command. Once done you can switch the upstream with Terminus to "drupal-recommended" accordingly
(`{$this->getTerminusExecutable()} site:upstream:set {$this->site()->getName()} drupal-recommended`).
EOD
        );
    }

    /**
     * Prints advice related to "empty" upstream.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function adviseOnEmpty(): void
    {
        $localPath = $this->getLocalSitePath(false);
        $this->setGit($localPath);
        $isBuildTools = $this->isBuildToolsSite();
        if ($isBuildTools) {
            $this->writeln('This site was created by the process described by the Terminus Build Tools guide (https://pantheon.io/docs/guides/build-tools/).');
        }

        $upstreamConfComposerJsonPath = Files::buildPath($localPath, 'upstream-configuration', 'composer.json');
        if (is_file($upstreamConfComposerJsonPath) && !$isBuildTools) {
            // Repository contents matches either "drupal-project" or "drupal-recommended" upstream.

            $composerJsonContent = file_get_contents($upstreamConfComposerJsonPath);
            if (false === strpos($composerJsonContent, 'drupal/core-recommended')) {
                // Repository contents matches "drupal-recommended" upstream.

                $this->getGit()->addRemote(
                    self::DRUPAL_RECOMMENDED_GIT_REMOTE_URL,
                    self::DRUPAL_RECOMMENDED_UPSTREAM_ID
                );
                if ($this->areGitReposWithCommonCommits(self::DRUPAL_RECOMMENDED_UPSTREAM_ID)) {
                    $this->output()->writeln(
                        <<<EOD
Advice: switch the upstream to "drupal-recommended" with Terminus -
`{$this->getTerminusExecutable()} site:upstream:set {$this->site()->getName()} drupal-recommended`.
EOD
                    );

                    return;
                }
            }

            $this->output()->writeln(
                <<<EOD
Advice: convert the site to use "drupal-recommended" Pantheon Upstream
(`{$this->getTerminusExecutable()} conversion:drupal-recommended {$this->site()->getName()}`) and then switch
the upstream with Terminus to "drupal-recommended" accordingly
(`{$this->getTerminusExecutable()} site:upstream:set {$this->site()->getName()} drupal-recommended`).
EOD
            );

            return;
        }

        if ($isBuildTools) {
            // Build artifact created by Terminus Build Tools plugin is present.
            $this->output()->writeln(
                <<<EOD
Advice: you might want to convert to drupal-recommended if you are not using Continuous Integration (e.g. running tests, compiling css, etc).
Otherwise, you should stay on "empty" upstream and the Terminus Build Tools (https://pantheon.io/docs/guides/build-tools/) workflow.

If you wish to convert to drupal-recommended, you could do so by using `conversion:composer` Terminus command
(i.e. `{$this->getTerminusExecutable()} conversion:composer {$this->site()->getName()}`). Once done you can switch the upstream with
Terminus to "drupal-recommended" accordingly (`{$this->getTerminusExecutable()} site:upstream:set {$this->site()->getName()} drupal-recommended`).
EOD
            );

            return;
        }

        $this->output()->writeln(
            <<<EOD
Advice: convert the site to a Composer managed one by using `conversion:composer` Terminus command
(i.e. `{$this->getTerminusExecutable()} conversion:composer {$this->site()->getName()}`). Once done you can switch the upstream with
Terminus to "drupal-recommended" accordingly (`{$this->getTerminusExecutable()} site:upstream:set {$this->site()->getName()} drupal-recommended`).
You could also stay in the current upstream if you prefer so.
EOD
        );
    }
}
