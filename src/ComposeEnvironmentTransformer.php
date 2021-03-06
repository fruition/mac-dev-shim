<?php declare(strict_types=1);

namespace Fruition\MacDevShim;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * Create a local override file to be merged with the source docker-compose.yml
 * file which uses NFS mounts. Set the COMPOSE_FILE environment variable to
 * use the override.
 *
 * @see https://docs.docker.com/compose/reference/envvars/#compose_file
 * @see https://docs.docker.com/compose/extends/#understanding-multiple-compose-files
 */
class ComposeEnvironmentTransformer {

  /**
   * Finder.
   *
   * @var \Symfony\Component\Finder\Finder
   */
  protected $finder;

  /**
   * PWD of calling script.
   *
   * @var string
   */
  protected $pwd;

  /**
   * File system.
   *
   * @var \Symfony\Component\Filesystem\Filesystem
   */
  protected $fileSystem;

  /**
   * Constructor.
   */
  public function __construct() {
    $this->finder = (new Finder())
      ->name('docker-compose.yml')
      ->depth('== 0')
      ->ignoreUnreadableDirs();
    $this->pwd = getenv('PWD');
    $this->fileSystem = new Filesystem();
  }

  /**
   * Transform the environment for Mac OS X.
   */
  public function transform(): void {
    // This is called from the bash script so this is a known variable.
    $dir = $this->pwd;
    // Recursively find docker-compose.yml
    $tree = [$dir];
    while ($dir) {
      $up = dirname($dir);
      $tree[] = $up;
      $dir = $up != '/' ? $up : FALSE;
    }
    // Break on first file found, which is why we iterate ourselves.
    foreach ($tree as $dir) {
      $finder = clone $this->finder;
      $found = $finder->in($dir);
      if (count($found)) {
        $result = iterator_to_array($found);
        break;
      }
    }
    if (empty($result)) {
      throw new \RuntimeException('Could not find docker-compose.yml in this or any parent directory.');
    }
    $baseYaml = Yaml::parse(reset($result)->getContents());
    $cacheDir = getenv('HOME') . '/Library/Caches/FruitionMacDevShim';
    $cachedFile = $cacheDir . '/' . crc32(serialize($baseYaml) . $dir) . '.yml';
    if (!$this->fileSystem->exists($cachedFile)) {
      $this->fileSystem->mkdir($cacheDir);
      $this->fileSystem->dumpFile($cachedFile, Yaml::dump($this->createOverrideYaml($baseYaml)));
    }
    // The overrides may be empty but we avoid re-computing it based on the hash.
    echo "$dir/docker-compose.yml:$cachedFile";
  }

  /**
   * Create override Yaml.
   *
   * @param array $baseYaml
   *   Base/source Yaml.
   *
   * @return array
   *   Override file contents.
   */
  protected function createOverrideYaml(array $baseYaml): array {
    if (empty($baseYaml['version'])) {
      throw new \RuntimeException('Must specify a version in the base docker-compose.yml file');
    }
    // docker-compose errors on mismatched versions in overrides.
    $overrideYaml = [
      'version' => $baseYaml['version'],
    ];
    $sourcePathMap = [];
    foreach ($baseYaml['services'] as $serviceName => $service) {
      if (!empty($service['volumes'])) {
        foreach ($service['volumes'] as $volumeSpec) {
          // We only handle simple specs for now.
          if (!is_string($volumeSpec)) {
            continue;
          }
          // Match a bind-mount volume source relative to the project root.
          if (preg_match('/(^\..*):(.*)$/U', $volumeSpec, $bindMounted)) {
            // Local source starting with '.'
            $source = $bindMounted[1];
            // Destination with options, e.g. ':ro'
            $destWithOptions = $bindMounted[2];
            // Only mount directories with NFS.
            if (!is_dir($this->pwd . '/' . $source)) {
              continue;
            }

            if (empty($sourcePathMap[$source])) {
              // Per docs: "Entries for volumes and devices are merged using the mount path in the container"
              $name = 'nfs' . count($sourcePathMap);
              $overrideYaml['volumes'][$name]
                = $this->getNfsVolumeDefinition($source);
              $sourcePathMap[$source] = $name;
            }
            $overrideYaml['services'][$serviceName]['volumes'][]
              = "{$sourcePathMap[$source]}:$destWithOptions";
            $overrideYaml['services'][$serviceName]['environment']['HOST_OS']
              = 'Darwin';
          }
        }
      }
    }
    return $overrideYaml;
  }

  /**
   * Get an NFS volume definition for a particular path.
   *
   * @param string $relativeSource
   *   The relative source.
   *
   * @return array
   *   Volume definition for inclusion in the override Yaml.
   */
  protected function getNfsVolumeDefinition(string $relativeSource): array {
    $local = ltrim(ltrim($relativeSource, '.'), '/');
    if (strlen($local) > 0) {
      $local = "/$local";
    }
    return [
      'driver' => 'local',
      'driver_opts' => [
        'type' => 'nfs',
        'o' => 'addr=host.docker.internal,rw,nolock,hard,nointr,nfsvers=3',
        'device' => ":{$this->pwd}$local",
      ]
    ];
  }

}
