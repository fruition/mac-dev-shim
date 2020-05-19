<?php declare(strict_types=1);

namespace Fruition\MacDevShim;

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
   * Constructor.
   */
  public function __construct() {
    $this->finder = (new Finder())
      ->ignoreVCS(TRUE)
      ->name('docker-compose.yml')
      ->ignoreUnreadableDirs();
    $this->pwd = getenv('PWD');
  }

  /**
   * Transform the environment for Mac OS X.
   */
  public function transform() {
    // This is called from the bash script so this is a known variable.
    $dir = $this->pwd;
    // Recursively find docker-compose.yml
    $tree = [$dir];
    while ($dir) {
      $up = dirname($dir);
      $tree[] = $up;
      $dir = $up != '/' ? $up : FALSE;
    }
    foreach ($tree as $dir) {
      $finder = clone $this->finder;
      $found = $finder->in($dir);
      if (count($found)) {
        $result = iterator_to_array($found);
        break;
      }
    }
    if (empty($result)) {
      throw new \RuntimeException('Could not find docker-compose.yml in any parent directory.');
    }
    $baseYaml = Yaml::parse(reset($result)->getContents());
    $cachedFile = getenv('HOME') . '/Library/Cache/FruitionMacDevShim/' . crc32(serialize($baseYaml)) . '.yml';
    if (!file_exists($cachedFile)) {
      file_put_contents($cachedFile, Yaml::dump($this->createOverrideYaml($baseYaml)));
    }
    putenv("COMPOSE_FILE=$dir/docker-compose.yml:$cachedFile");
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

            if (empty($sourcePathMap[$source])) {
              // Per docs: "Entries for volumes and devices are merged using the mount path in the container"
              $name = 'nfs' . count($sourcePathMap);
              $overrideYaml['services'][$serviceName]['volumes'][$name]
                = $this->getNfsVolumeDefinition($source);
              $sourcePathMap[$source] = $name;
            }
            $overrideYaml['services'][$serviceName][] = "{$sourcePathMap[$source]}:$destWithOptions";
          }
        }
      }
    }
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
    $local = ltrim('/', ltrim('.', $relativeSource));
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
