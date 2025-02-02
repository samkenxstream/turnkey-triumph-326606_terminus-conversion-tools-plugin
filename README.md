# Terminus Conversion Tools Plugin

[![GitHub Actions](https://github.com/pantheon-systems/terminus-conversion-tools-plugin/actions/workflows/workflow.yml/badge.svg)](https://github.com/pantheon-systems/terminus-conversion-tools-plugin/actions/workflows/workflow.yml)
[![Early Access](https://img.shields.io/badge/Pantheon-Early_Access-yellow?logo=pantheon&color=FFDC28)](https://pantheon.io/docs/oss-support-levels#early-access)

[![Terminus 3.x Compatible](https://img.shields.io/badge/terminus-3.x-green.svg)](https://github.com/pantheon-systems/terminus/tree/3.x)

The main purposes of the Conversion Tools project are to ease the conversion of a Drupal based site into a composer managed Drupal site.

Adds the following Terminus commands:
* `conversion:advise`
* `conversion:composer`
* `conversion:update-from-deprecated-upstream`
* `conversion:push-to-multidev`
* `conversion:release-to-dev`
* `conversion:restore-dev`
* `conversion:enable-ic`
* `conversion:import-site`
* `conversion:validate-gitignore`

Learn more about Terminus Plugins in the [Terminus Plugins documentation](https://pantheon.io/docs/terminus/plugins)

## Status

In active development

## Usage
* Run `terminus conversion:advise` to analyze the current state of the site and give advice on the next steps
* Run `terminus conversion:composer` to convert a standard Drupal site into a Drupal site managed by Composer
* Run `terminus conversion:update-from-deprecated-upstream` to convert a "drupal-project" upstream-based site into a "drupal-recommended" upstream-based one
* Run `terminus conversion:push-to-multidev` to push the converted site to a multidev environment
* Run `terminus conversion:release-to-dev` to release a converted Drupal site managed by Composer to the dev environment
* Run `terminus conversion:restore-dev` to restore the dev environment branch to its original state
* Run `terminus conversion:enable-ic` to enable Pantheon Integrated Composer for the site
* Run `terminus conversion:import-site` to create a site based on "drupal-recommended" upstream from imported code, database, and files
* Run `conversion:validate-gitignore` to validate Git/Composer project and update .gitignore file accordingly

## Installation

To install this plugin using Terminus 3:
```
terminus self:plugin:install terminus-conversion-tools-plugin
```
