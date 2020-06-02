# Fruition Mac OS Development Shim

## About this package: Do I need this?

### Motivation

Fruition has standardized local development on to a "native" Docker workflow with `docker-compose`; that is, we intend
as much as possible to run the same images locally as in production, and in doing so avoid the unavoidable environment
disparity when using various "Docker Plus" solutions such as Lando, DDEV, Docksal and others. This is not to disparage
these other tools - as they have their utility and are employed successfully by many users - but a more native workflow
is best suited toward our needs. Many of the above tools utilize `docker-compose` under the hood, anyway, and add
various syntactic and runtime sugar to enhance user experience.

### Mac OS and bind-mount filesystem performance

As explained well [elsewhere](https://docs.docker.com/docker-for-mac/osxfs-caching/), bind-mount performance in Docker
for Mac is, well, so poor as to border unusable. Using the `cached` or `delegated` options for such mounts help improve
performance marginally, however our developers have found it to remain unacceptable for daily use.
[Sean Handley](https://medium.com/@sean.handley/how-to-set-up-docker-for-mac-with-native-nfs-145151458adc),
[Jeff Geerling](https://www.jeffgeerling.com/blog/2020/revisiting-docker-macs-performance-nfs-volumes) and others have
described methods to configure and use NFS mounts with `docker-compose`, and DDEV-local
[includes automatic NFS mount configuration](https://github.com/drud/ddev/pull/1871/files) as part of its Mac OS
localization. However, the former approaches require manual configuration for every project, and the latter is fully
integrated into a heavier toolchain than we wish to employ.

## What this package does

This package exists to provide a wrapper around `docker-compose` invocation to create and utilize a Docker Compose
[override file](https://docs.docker.com/compose/extends/#understanding-multiple-compose-files) to utilize NFS instead
of bind-mounts, and provide simple tooling to perform one-time NFS setup.

## Installation

Fruition is a PHP shop and as such we assume our developers have PHP and `composer` installed locally at a minimum.
This package uses the Symfony Yaml component and thus must be installed using Composer. Beyond this packaging,
the shim must only be available in a known location that can be added to the developer's `PATH` to be selected by the
shell ahead of of the globally-installed `docker-compose` executable. This could be done per project with
[direnv](https://direnv.net/), or (simplest way) by including your global composer `bin` directory, per below.

### Install/Transition to Docker Desktop for Mac

If you haven't already, install [Docker Desktop For Mac](https://docs.docker.com/docker-for-mac/install/). If you
previously used Docker Toolbox, follow the instructions for transitioning. The Docker installer for Mac OS includes
`docker-compose`.

### Install the shim with [Composer](https://getcomposer.org):

```
composer global require fruition/mac-dev-shim
```

Add (if you haven't already) the following to your `~/.bash_profile` (if using Bash, which is the Mac OS X default):

```bash
PATH="$HOME/.composer/vendor/bin:$PATH"
```

You will need to restart your Terminal session for the new value to work.

### NFS debugging

* Use `showmount -e` to find out what is exported via NFS. If you don't see a parent of your project directory in there,
    then NFS can't work.
* If nothing is showing, use `nfsd checkexports` and read carefully for errors.
* Use `ps -ef | grep nfsd` to make sure nfsd is running.
* Restart nfsd with `sudo nfsd restart`
* Run Console.app and put "nfsd" in the search box at the top. `sudo nfsd restart` and read the messages carefully.

### Shim debugging

To view the "combined" compose file Yaml, run `docker-compose config`.

## Usage

After installing, call `docker-compose` as usual.

### Copyright and Licenses.

Copyright 2020 Fruition Growth LLC. MIT licensed.

Some components incorporated and licensed from:

* [DDEV-local](https://github.com/drud/ddev): Apache 2.0 license. See incorporated files for list of modifications.
