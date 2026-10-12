// Runs a script inside a throwaway container. Source (including .git) is
// streamed in with tar, so no host bind mounts are needed.
def inDocker(String image, String script) {
    writeFile file: '.ci-step.sh', text: "set -eux\n" + script + "\n"
    sh """
        set -eu
        tar -cf - . | docker run --rm -i --memory 6g --cpus 4 \\
          -e CI=true -e HOME=/tmp/home ${image} \\
          sh -c 'mkdir -p /src /tmp/home && cd /src && tar -xf - && git config --global --add safe.directory /src 2>/dev/null || true; sh .ci-step.sh'
    """
}

def setup = '''apt-get update -qq && apt-get install -y -qq git unzip zip nodejs curl >/dev/null
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer --quiet'''

def quality = setup + '''
composer validate --strict
composer install --no-interaction --no-progress --prefer-dist
composer audit --locked --no-interaction
composer check
find assets/js -type f -name '*.js' -print0 | xargs -0 -n1 node --check
bash -n bin/build-release.sh'''

def pkg = setup + '''
apt-get install -y -qq bash >/dev/null
bash tests/build-release-test.sh
bash bin/build-release.sh
artifact="$(find dist -maxdepth 1 -type f -name 'ffl-bridge-for-woocommerce-*.zip' -print -quit)"
test -n "${artifact}"
unzip -t "${artifact}"
if unzip -Z1 "${artifact}" | grep -E '(^|/)(\\.distignore|\\.git|\\.github|bin|tests|vendor|composer\\.(json|lock)|php(cs|unit)\\.xml\\.dist|CHANGELOG\\.md|CONTRIBUTING\\.md|README\\.md|SECURITY\\.md)(/|$)'; then
  echo 'Release archive contains a development-only path.' >&2
  exit 1
fi'''

// Mirrors .github/workflows/ci.yml. release.yml and wporg.yml (tag/deploy) stay on Actions.
pipeline {
    agent { label 'linux-docker' }
    options { timeout(time: 30, unit: 'MINUTES'); timestamps(); disableConcurrentBuilds(); buildDiscarder(logRotator(numToKeepStr: '20')) }
    stages {
        stage('PHP quality') {
            parallel {
                stage('PHP 8.3') { steps { script { inDocker('php:8.3-cli', quality) } } }
                stage('PHP 8.4') { steps { script { inDocker('php:8.4-cli', quality) } } }
                stage('PHP 8.5') { steps { script { inDocker('php:8.5-cli', quality) } } }
            }
        }
        stage('Reproducible release package') { steps { script { inDocker('php:8.3-cli', pkg) } } }
    }
    post { always { deleteDir() } }
}
