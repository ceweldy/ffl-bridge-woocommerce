// Shadow only. Load this file from a reviewed, pinned SCM revision.
// Controller-owned SHADOW_WORKFLOW/SHADOW_EVENT/SHADOW_REF define one job per group.
def workflow = env.SHADOW_WORKFLOW ?: ''
def event = env.SHADOW_EVENT ?: ''
def ref = env.SHADOW_REF ?: ''
def approvedToolingSha = env.SHADOW_TOOLING_SHA ?: ''
if (!(approvedToolingSha ==~ /[0-9a-f]{40}/)) {
    error('Controller must pin SHADOW_TOOLING_SHA to an approved full commit SHA')
}
def allowed = ['ci', 'release', 'wporg']
if (!allowed.contains(workflow) || !['push', 'pull_request'].contains(event)) {
    error('Controller must bind SHADOW_WORKFLOW, SHADOW_EVENT and SHADOW_REF; see ci/jenkins/README.md')
}
if (!(ref == 'refs/heads/main' || ref ==~ /refs\/pull\/[1-9][0-9]*\/merge/ || ref ==~ /refs\/tags\/v[0-9A-Za-z._-]+/)) {
    error('Invalid controller-owned event ref')
}
// Job identity MUST be fixed to the group before the first run. No automatic triggers.
properties([
    parameters([string(name: 'SOURCE_SHA', defaultValue: '', description: 'Exact event commit: 40 lowercase hex characters')]),
    disableConcurrentBuilds(abortPrevious: workflow == 'ci' || (workflow == 'wporg' && event == 'pull_request')),
    buildDiscarder(logRotator(daysToKeepStr: '90', artifactDaysToKeepStr: '1'))
])
if (!(params.SOURCE_SHA ==~ /[0-9a-f]{40}/)) {
    error('SOURCE_SHA must be an exact 40-character SHA')
}
def repoUrl = 'https://github.com/ceweldy/ffl-bridge-woocommerce.git'
def runLane = { String phase, String phpVersion, String agentLabel, boolean receiveAssets = false ->
    node(agentLabel) {
        ws("${env.WORKSPACE}/${phase}-${phpVersion}") {
            deleteDir()
            def result = 'failure'
            def toolingSha = ''
            try {
                sh 'mkdir -p receipts'
                dir('tooling') {
                    checkout scm
                    toolingSha = sh(script: 'git rev-parse HEAD', returnStdout: true).trim()
                    if (toolingSha != approvedToolingSha) {
                        error('Tooling checkout differs from approved SHADOW_TOOLING_SHA')
                    }
                }
                dir('source') {
                    checkout([$class: 'GitSCM', branches: [[name: params.SOURCE_SHA]],
                        userRemoteConfigs: [[url: repoUrl,
                            credentialsId: env.SHADOW_SCM_CREDENTIALS ?: '',
                            refspec: '+refs/heads/*:refs/remotes/origin/* +refs/tags/*:refs/tags/* +refs/pull/*/merge:refs/pull/*/merge']],
                        extensions: [[$class: 'CloneOption', shallow: false, noTags: false, honorRefspec: true]]])
                }
                if (receiveAssets) {
                    unstash 'release-assets'
                }
                withEnv(["SOURCE_SHA=${params.SOURCE_SHA}", "SHADOW_WORKFLOW=${workflow}",
                         "SHADOW_EVENT=${event}", "SHADOW_REF=${ref}",
                         "PHP_LANE=${phpVersion}", "SHADOW_STAGE=${phase}", "TOOLING_SHA=${toolingSha}"]) {
                    sh '''python3 tooling/ci/jenkins/shadow.py --source source --sha "$SOURCE_SHA" \
                        --workflow "$SHADOW_WORKFLOW" --event "$SHADOW_EVENT" --ref "$SHADOW_REF" \
                        --stage "$SHADOW_STAGE" --php "$PHP_LANE" --output receipts'''
                }
                if (phase == 'package' && workflow != 'ci') {
                    // Immutable in-run transfer; consumer never rebuilds release bytes.
                    stash name: 'release-assets', includes: 'source/dist/*.zip,source/dist/*.sha256,receipts/package.json', allowEmpty: false
                }
                result = 'success'
            } catch (org.jenkinsci.plugins.workflow.steps.FlowInterruptedException interrupted) {
                result = 'aborted'
                throw interrupted
            } finally {
                // Also records checkout failures and Jenkins timeout/abort. Agent-loss remains
                // visible in Jenkins result even when a workspace cannot be archived.
                try {
                    timeout(time: 2, unit: 'MINUTES') {
                        // Inline guard is held by trusted pipeline code, not reloaded after the build.
                        sh '''/usr/bin/python3 - <<'PY'
# ffl archive guard begin
import pathlib
import stat

for directory, patterns in (('receipts', ('*.json', '*.txt')), ('source/dist', ('*.zip', '*.sha256'))):
    root = pathlib.Path(directory)
    if not root.exists() and not root.is_symlink():
        continue
    for parent in (root, *root.parents):
        info = parent.lstat()
        if not stat.S_ISDIR(info.st_mode):
            raise SystemExit('Archive directory must be a regular non-symlink directory: ' + directory)
    for pattern in patterns:
        for path in root.glob(pattern):
            if not stat.S_ISREG(path.lstat().st_mode):
                raise SystemExit('Archive accepts only regular non-symlink files: ' + path.name)
# ffl archive guard end
PY'''
                        writeFile file: "receipts/jenkins-${phase}-${phpVersion}.json", text: groovy.json.JsonOutput.toJson([
                            schema: 1, shadow: true, authority: 'github-actions', repository: 'ceweldy/ffl-bridge-woocommerce',
                            source_sha: params.SOURCE_SHA, tooling_sha: toolingSha, workflow: workflow,
                            event: event, ref: ref, stage: phase, php_lane: phpVersion, status: result,
                            build_number: env.BUILD_NUMBER, build_url: env.BUILD_URL
                        ]) + '\n'
                        archiveArtifacts artifacts: 'receipts/*.json,receipts/*.txt,source/dist/*.zip,source/dist/*.sha256',
                            allowEmptyArchive: false, fingerprint: true
                    }
                } finally {
                    timeout(time: 1, unit: 'MINUTES') { deleteDir() }
                }
            }
        }
    }
}

def qualityStage = {
    stage('Quality') {
        if (workflow == 'ci') {
            // failFast defaults to false: all PHP lanes finish independently.
            parallel([
                'PHP 8.3': { timeout(time: 10, unit: 'MINUTES') { runLane('quality', '8.3', 'ffl-php83') } },
                'PHP 8.4': { timeout(time: 10, unit: 'MINUTES') { runLane('quality', '8.4', 'ffl-php84') } },
                'PHP 8.5': { timeout(time: 10, unit: 'MINUTES') { runLane('quality', '8.5', 'ffl-php85') } },
                failFast: false
            ])
        } else {
            runLane('quality', '8.3', 'ffl-php83')
        }
    }
}
def packageStage = {
    stage('Package') { runLane('package', '8.3', 'ffl-package') }
}
if (workflow == 'ci') {
    qualityStage()
    timeout(time: 5, unit: 'MINUTES') { packageStage() }
} else {
    // Actions applies its quality/dry-run job limit to all of these steps together.
    timeout(time: workflow == 'wporg' ? 15 : 10, unit: 'MINUTES') {
        qualityStage()
        packageStage()
        if (workflow == 'wporg') {
            stage('Plugin Check') {
                // This label must select exclusive single-executor Docker workers.
                runLane('plugin-check', '8.3', 'ffl-wporg', true)
            }
        }
    }
    stage('Verify transferred assets and record publication plan') {
        timeout(time: workflow == 'wporg' ? 15 : 5, unit: 'MINUTES') { runLane('plan', '8.3', 'ffl-package', true) }
    }
    echo 'Shadow complete. GitHub Actions remains the publisher; no release or SVN mutation exists in this pipeline.'
}
