#!/usr/bin/env php
<?php declare (strict_types = 1);
/**
 * Update Pi-hole lists from remote sources
 *
 * @author  Jack'lul <jacklul.github.io>
 * @license MIT
 * @link    https://github.com/jacklul/pihole-updatelists
 */

// A hash in case we're gonna force an update - f362448aaf23adbf8a900b3616a6719c
define('GITHUB_LINK', 'https://github.com/jacklul/pihole-updatelists'); // Link to Github page
define('GITHUB_LINK_RAW', 'https://raw.githubusercontent.com/jacklul/pihole-updatelists'); // URL serving raw files from the repository

/**
 * Print (and optionally log) string
 *
 * @param string $str
 * @param string $severity
 * @param bool   $logOnly
 *
 * @throws RuntimeException
 */
function printAndLog($str, $severity = 'INFO', $logOnly = false)
{
    global $config, $lock;

    if (!in_array(strtoupper($severity), ['DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR'])) {
        throw new RuntimeException('Invalid log severity: ' . $severity);
    }

    if (!empty($config['LOG_FILE'])) {
        $flags = FILE_APPEND;

        if (strpos($config['LOG_FILE'], '-') === 0) {
            $flags              = 0;
            $config['LOG_FILE'] = substr($config['LOG_FILE'], 1);
        }

        // Do not overwrite log files until we have a lock (this could mess up log file if another instance is already running)
        if ($flags !== null || $lock !== null) {
            if (!file_exists($config['LOG_FILE']) && !@touch($config['LOG_FILE'])) {
                throw new RuntimeException('Unable to create log file: ' . $config['LOG_FILE']);
            }

            $lines = preg_split('/\r\n|\r|\n/', ucfirst(trim($str)));
            foreach ($lines as &$line) {
                $line = '[' . date('Y-m-d H:i:s e') . '] [' . strtoupper($severity) . ']' . "\t" . $line;
            }
            unset($line);

            file_put_contents(
                $config['LOG_FILE'],
                implode(PHP_EOL, $lines) . PHP_EOL,
                $flags | LOCK_EX
            );
        }
    }

    if ($logOnly) {
        return;
    }

    print $str;
}

/**
 * Check for required stuff
 *
 * Setting environment variable IGNORE_OS_CHECK allows to run this script on Windows
 */
function checkDependencies()
{
    // Do not run on PHP lower than 7.0
    if ((float) PHP_VERSION < 7.0) {
        printAndLog('Minimum PHP 7.0 is required to run this script!' . PHP_EOL, 'ERROR');
        exit(1);
    }

    // Windows is obviously not supported (invironment variable IGNORE_OS_CHECK overrides this)
    if (stripos(PHP_OS, 'WIN') === 0 && empty(getenv('IGNORE_OS_CHECK'))) {
        printAndLog('Windows is not supported!' . PHP_EOL, 'ERROR');
        exit(1);
    }

    // Check for required PHP extensions
    $extensions = [
        'pdo',
        'pdo_sqlite',
    ];

    foreach ($extensions as $extension) {
        if (!extension_loaded($extension)) {
            printAndLog('Missing required PHP extension: ' . $extension . PHP_EOL, 'ERROR');
            print 'You can install it using `apt-get install php-' . str_replace('_', '-', $extension) . '`' . PHP_EOL;
            exit(1);
        }
    }
}

/**
 * Check for optional stuff
 */
function checkOptionalDependencies()
{
    // Check for recommended PHP extensions
    $missingExtensions = [];
    $extensions        = [
        'intl',
        'curl',
    ];

    foreach ($extensions as $extension) {
        if (!extension_loaded($extension)) {
            printAndLog('Missing recommended PHP extension: php-' . $extension . PHP_EOL, 'WARNING');
            incrementStat('warnings');
            $missingExtensions[] = 'php-' . str_replace('_', '-', $extension);
        }
    }

    if (count($missingExtensions) > 0) {
        print 'You can install missing extensions using `apt-get install ' . implode(' ', $missingExtensions) . '`' . PHP_EOL . PHP_EOL;
    }
}

/**
 * Returns array of defined options
 *
 * @return array
 */
function getDefinedOptions()
{
    return [
        'help'       => [
            'long'        => 'help',
            'short'       => 'h',
            'function'    => 'printHelp',
            'description' => 'This help message',
        ],
        'no-gravity' => [
            'long'        => 'no-gravity',
            'short'       => 'n',
            'description' => 'Force no gravity update',
        ],
        'no-reload'  => [
            'long'        => 'no-reload',
            'short'       => 'b',
            'description' => 'Force no lists reload',
        ],
        'verbose'    => [
            'long'        => 'verbose',
            'short'       => 'v',
            'description' => 'Turn on verbose mode',
        ],
        'debug'      => [
            'long'        => 'debug',
            'short'       => 'd',
            'description' => 'Turn on debug mode',
        ],
        'yes'        => [
            'long'        => 'yes',
            'short'       => 'y',
            'description' => 'Automatically reply YES to all questions',
        ],
        'force'      => [
            'long'        => 'force',
            'short'       => 'f',
            'description' => 'Force update without checking for newest version',
            'requires'    => ['update'],
        ],
        'update'     => [
            'long'        => 'update',
            'function'    => 'updateScript',
            'description' => 'Update the script using selected git branch',
            'conflicts'   => ['rollback'],
        ],
        'rollback'   => [
            'long'        => 'rollback',
            'function'    => 'rollbackScript',
            'description' => 'Rollback script version to previous',
            'conflicts'   => ['update'],
        ],
        'version'    => [
            'long'        => 'version',
            'function'    => 'printVersion',
            'description' => 'Show script version checksum (and if update is available)',
            'conflicts'   => ['update', 'rollback'],
        ],
        'debug-print' => [
            'long'        => 'debug-print',
            'function'    => 'showDebugPrint',
            'description' => 'Shows debug print only',
            'hidden'      => true,
        ],
        'config'     => [
            'long'                  => 'config::',
            'description'           => 'Load alternative configuration file',
            'parameter-description' => 'file',
        ],
        'git-branch' => [
            'long'                  => 'git-branch::',
            'description'           => 'Select git branch to pull remote checksum and update from',
            'parameter-description' => 'branch',
            'requires'              => ['version', 'update'],
        ],
        'env'        => [
            'long'        => 'env',
            'short'       => 'e',
            'description' => 'Load configuration from environment variables',
        ],
    ];
}

/**
 * Re-run the script with sudo when not running as root
 *
 * This check is ignored if script is not installed
 */
function requireRoot()
{
    global $isRoot;

    if (!isset($isRoot) || $isRoot === null) {
        $isRoot = null;

        if (function_exists('posix_getuid')) {
            $isRoot = posix_getuid() === 0;
        } else {
            $isRoot = shell_exec('whoami') === 'root';
        }
    }

    if (!$isRoot && strpos(basename($_SERVER['argv'][0]), '.php') === false) {
        print 'root privileges required' . PHP_EOL;
        exit(1);
    }
}

/**
 * Parse command-line options
 */
function parseOptions()
{
    $definedOptions = getDefinedOptions();
    $shortOpts      = [];
    $longOpts       = [];

    foreach ($definedOptions as $i => $data) {
        if (isset($data['long'])) {
            if (in_array($data['long'], $longOpts)) {
                throw new RuntimeException('Unable to define long option because it is already defined: ' . $data['long']);
            }

            $longOpts[] = $data['long'];
        }

        if (isset($data['short'])) {
            if (in_array($data['short'], $longOpts)) {
                throw new RuntimeException('Unable to define short option because it is already defined: ' . $data['short']);
            }

            $shortOpts[] = $data['short'];
        }
    }

    $options = getopt(implode('', $shortOpts), $longOpts);

    // --help will take priority always
    if ((isset($options['help']) || isset($options['h'])) && isset($definedOptions['help']['function'])) {
        $runFunction = $definedOptions['help']['function'];
        $runFunction($options, loadConfig($options));
        exit;
    }

    // If short is used set the long one
    foreach ($options as $option => $data) {
        foreach ($definedOptions as $definedOptionsIndex => $definedOptionsData) {
            $definedOptionsData['short'] = isset($definedOptionsData['short']) ? str_replace(':', '', $definedOptionsData['short']) : '';
            $definedOptionsData['long']  = isset($definedOptionsData['long']) ? str_replace(':', '', $definedOptionsData['long']) : '';

            if (
                $definedOptionsData['short'] === $option ||
                $definedOptionsData['long'] === $option
            ) {
                // Replaces short option with long in $options
                if (
                    !empty($definedOptionsData['short']) &&
                    !empty($definedOptionsData['long']) &&
                    $definedOptionsData['short'] === $option
                ) {
                    $options[$definedOptionsData['long']] = $data;
                    unset($options[$option]);
                }

                // Set function to run if it is defined for this option
                if (!isset($runFunction) && isset($definedOptionsData['function']) && function_exists($definedOptionsData['function'])) {
                    $runFunction = $definedOptionsData['function'];
                }
            }
        }
    }

    foreach ($options as $option => $data) {
        if (isset($definedOptions[$option]['conflicts'])) {
            foreach ($definedOptions[$option]['conflicts'] as $conflictingOption) {
                if (isset($options[$conflictingOption])) {
                    print 'Options "--' . $option . '" and "--' . $conflictingOption . '" cannot be used together' . PHP_EOL;
                    exit(1);
                }
            }
        } elseif (isset($definedOptions[$option]['requires'])) {
            $requirementsMet = 0;

            $anotherOptionsList = '';
            foreach ($definedOptions[$option]['requires'] as $requiredOption) {
                if (isset($options[$requiredOption])) {
                    $requirementsMet++;
                }

                if (!empty($anotherOptionsList)) {
                    $anotherOptionsList .= ', ';
                }

                $anotherOptionsList .= '"--' . $requiredOption . '"';
            }

            if ($requirementsMet === 0) {
                print 'Option "--' . $option . '" can only be used with specific option(s) (' . $anotherOptionsList . ')' . PHP_EOL;
                exit(1);
            }
        }
    }

    global $argv;
    unset($argv[0]); // Remove path to self

    // Split "-asdf" into "-a -s -d -f" to prevent a bug (issues/66#issuecomment-787836262)
    foreach ($argv as $key => $option) {
        if (substr($option, 0, 1) === '-' && substr($option, 0, 2) !== '--') {
            foreach (str_split(substr($option, 1)) as $character) {
                $argv[] = '-' . $character;
            }

            unset($argv[$key]);
        }
    }

    // Remove recognized options from argv[]
    foreach ($options as $option => $data) {
        $result = array_filter($argv, function ($el) use ($option) {
            /** @var mixed $option */
            return strpos($el, $option) !== false;
        });

        if (!empty($result)) {
            unset($argv[key($result)]);
        }

        if (isset($definedOptions[$option]['short'])) {
            $shortOption = $definedOptions[$option]['short'];

            $result = array_filter($argv, function ($el) use ($shortOption) {
                return strpos($el, $shortOption) !== false;
            });

            if (!empty($result) && !preg_match('/^--' . $shortOption . '/', $argv[key($result)])) {
                $argv[key($result)] = str_replace('-' . $shortOption, '', $argv[key($result)]);

                if ($argv[key($result)] === '-' || $argv[key($result)] === '') {
                    unset($argv[key($result)]);
                }

                if (empty($argv[key($result)])) {
                    unset($argv[key($result)]);
                }
            }
        }
    }

    // When unknown option is used
    if (count($argv) > 0) {
        print 'Unknown option(s): ' . implode(' ', $argv) . PHP_EOL;
        exit(1);
    }

    // Run the function
    if (isset($runFunction)) {
        $runFunction($options, loadConfig($options));
        exit;
    }

    requireRoot(); // Require root privileges

    return $options;
}

/**
 * Register object oriented wrapper for cURL/file_get_contents
 *
 * @return void
 */
function registerHttpClient()
{
    if (!class_exists('HttpClient')) {
        if (function_exists('curl_init')) {
            class HttpClient
            {
                /**
                 * @var CurlHandle
                 */
                private $curl;

                /**
                 * @param array $config
                 */
                public function __construct(array $config = null)
                {
                    $this->init();
                    $this->setopt(CURLOPT_RETURNTRANSFER, true);
                    $this->setopt(CURLOPT_FOLLOWLOCATION, true);

                    if (is_array($config)) {
                        isset($config['timeout']) && $this->setopt(CURLOPT_TIMEOUT, $config['timeout']);
                        isset($config['user_agent']) && $this->setopt(CURLOPT_USERAGENT, $config['user_agent']);
                    }
                }

                /**
                 * @param string $function
                 * @param array  $parameters
                 *
                 * @return mixed
                 */
                public function __call($function, array $parameters)
                {
                    $function = strtolower($function);

                    if ($function === 'init' || $function === 'multi_init') {
                        is_resource($this->curl) && curl_close($this->curl);

                        return $this->curl = call_user_func_array('curl_' . $function, $parameters);
                    } else {
                        array_unshift($parameters, $this->curl);
                        return call_user_func_array('curl_' . $function, $parameters);
                    }
                }

                /**
                 * @param string $url
                 *
                 * @return string|false
                 */
                public function get($url)
                {
                    $this->setopt(CURLOPT_URL, $url);

                    return $this->exec();
                }

                /**
                 * @return int|null
                 */
                public function getStatusCode()
                {
                    return (int) $this->getinfo(CURLINFO_HTTP_CODE);
                }

                /**
                 * @param string $url
                 *
                 * @return string|false
                 */
                public function getWithHeaders($url)
                {
                    $this->setopt(CURLOPT_HEADER, true);
                    $return = $this->get($url);
                    $this->setopt(CURLOPT_HEADER, false);

                    return $return;
                }

                /**
                 * @return int
                 */
                public function getHeaderSize()
                {
                    return $this->getinfo(CURLINFO_HEADER_SIZE);
                }
            }
        } else {
            class HttpClient
            {
                /**
                 * @var array
                 */
                private $streamContextArray;

                /**
                 * @param string
                 */
                private $headers;

                /**
                 * @param string $url
                 */
                public function __construct(array $config = null)
                {
                    if (is_array($config)) {
                        $this->streamContextArray = [
                            'http' => [
                                'timeout'         => $config['timeout'],
                                'user_agent'      => $config['user_agent'],
                                'follow_location' => true,
                            ],
                        ];
                    }
                }

                /**
                 * @param string $function
                 * @param array  $parameters
                 *
                 * @return mixed
                 */
                public function __call($function, array $parameters)
                {
                    return null;
                }

                /**
                 * @param string $url
                 * @param string $withHeaders
                 *
                 * @return string|false
                 */
                public function get($url, $withHeaders = false)
                {
                    $streamContext = $this->streamContextArray;
                    if ($withHeaders === true) {
                        $streamContext['http']['ignore_errors'] = false;

                        global $http_response_header;
                    }

                    $this->headers = null;
                    $return        = file_get_contents($url, false, stream_context_create($streamContext));

                    if ($withHeaders === true) {
                        $headersAsString = '';
                        foreach ($http_response_header as $header) {
                            $headersAsString .= $header . "\r\n";
                        }

                        $this->headers = $headersAsString . "\r\n\r\n";

                        return $this->headers . $return;
                    }

                    if ($return === false || $return == '') {
                        return false;
                    }

                    return $return;
                }

                /**
                 * @return int|null
                 */
                public function getStatusCode()
                {
                    if ($this->headers !== null) {
                        preg_match_all('/HTTP.*(\d{3})/', $this->headers, $matches);

                        if (isset($matches[1]) && count($matches[1]) > 0) {
                            return (int) $matches[1][count($matches[1]) - 1];
                        }
                    }

                    return null;
                }

                /**
                 * @param string $url
                 *
                 * @return string|false
                 */
                public function getWithHeaders($url)
                {
                    return $this->get($url, true);
                }

                /**
                 * @return int
                 */
                public function getHeaderSize()
                {
                    return $this->headers !== null ? strlen($this->headers) : 0;
                }
            }
        }
    }
}

/**
 * Create HTTP client instance
 *
 * @param array|null $config
 *
 * @return HttpClient|void
 */
function createHttpClient(array $config = null)
{
    registerHttpClient();

    $defaultConfig = [
        'timeout'    => 60,
        'user_agent' => (function_exists('curl_version') ? 'curl/' . curl_version()['version'] . ' ' : '') . 'PHP/' . PHP_VERSION,
    ];
    $config = array_merge($defaultConfig, $config ?? []);

    return new HttpClient($config);
}

/**
 * Wrapper function for doing requests to files
 *
 * @param string $url
 *
 * @return string|false
 */
function fetchFileContents($url)
{
    if (file_exists($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        return file_get_contents($url);
    }

    global $httpClient;

    $result = $httpClient->get($url);

    if (is_int($httpStatusCode = $httpClient->getStatusCode()) && $httpStatusCode > 0 && substr((string) $httpStatusCode, 0, 1) !== '2') {
        global $customError;

        $customError = 'HTTP request failed with status code ' . $httpStatusCode;

        return false;
    }

    return $result;
}

/**
 * Fetch remote script file
 *
 * @param string $branch
 * @param bool   $debug
 *
 * @return string|false
 */
function fetchRemoteScript($branch = 'master', $debug = false)
{
    global $remoteScript, $customError;

    if (isset($remoteScript[$branch])) {
        return $remoteScript[$branch];
    }

    $httpClient  = createHttpClient(['timeout' => 15]);
    $response    = $httpClient->getWithHeaders(GITHUB_LINK_RAW . '/' . $branch . '/pihole-updatelists.php');
    $header_size = $httpClient->getHeaderSize();

    if ($response === false) {
        $customError = 'HTTP request failed';

        return false;
    }

    $headers = [];
    foreach (explode("\r\n", substr($response, 0, $header_size)) as $i => $line) {
        if ($i === 0) {
            preg_match('/(\d{3})/', $line, $matches);
            $headers['http_code'] = (int) $matches[1];
        } elseif (!empty($line)) {
            list($key, $value) = explode(': ', $line);

            $headers[$key] = $value;
        }
    }

    $remoteScript[$branch] = substr($response, $header_size);

    $isSuccessful = false;
    if ($headers['http_code'] === 200) {
        $isSuccessful = true;
    }

    if ($isSuccessful) {
        $firstLine = strtok($remoteScript[$branch], "\n");

        if (strpos($firstLine, '#!/usr/bin/env php') === false) {
            $customError = 'Returned remote script data doesn\'t seem to be valid';

            if ($debug === true) {
                print $customError . PHP_EOL;
                print 'First line: ' . $firstLine . PHP_EOL;

                exit(1);
            }

            return false;
        }

        return $remoteScript[$branch];
    }

    $customError = 'Failed to fetch remote file';

    return false;
}

/**
 * Check if script is up to date
 *
 * @param string $branch
 * @param bool   $debug
 *
 * @return string
 */
function isUpToDate($branch = 'master', $debug = false)
{
    $md5Self      = md5_file(__FILE__);
    $remoteScript = fetchRemoteScript($branch, $debug);

    if ($remoteScript === false) {
        return null;
    }

    if ($md5Self !== md5($remoteScript)) {
        return false;
    }

    return true;
}

/**
 * Returns currently selected branch
 *
 * @param array $options
 * @param array $config
 */
function getBranch(array $options = [], array $config = [])
{
    $branch = 'master';

    if (!empty($options['git-branch'])) {
        $branch = $options['git-branch'];
    } elseif (!empty($config['GIT_BRANCH'])) {
        $branch = $config['GIT_BRANCH'];
    }

    return $branch;
}

/**
 * Print help
 *
 * @param array $options
 * @param array $config
 */
function printHelp(array $options = [], array $config = [])
{
    $definedOptions = getDefinedOptions();
    $help           = [];
    $maxLen         = 0;

    foreach ($definedOptions as $option) {
        if (isset($option['hidden']) && $option['hidden'] === true) {
            continue;
        }

        $line = ' ';

        if (!isset($option['description'])) {
            continue;
        }

        if (isset($option['short'])) {
            $line .= '-' . $option['short'];
        }

        if (isset($option['long'])) {
            if (!empty(trim($line))) {
                $line .= ', ';
            }

            $line .= '--' . str_replace(':', '', $option['long']);

            if (isset($option['parameter-description'])) {
                $line .= '=<' . $option['parameter-description'] . '>';
            }
        }

        if (strlen($line) > $maxLen) {
            $maxLen = strlen($line);
        }

        $help[$line] = $option['description'];
    }

    printHeader();
    print 'Usage: ' . basename(__FILE__) . ' [OPTIONS...] ' . PHP_EOL . PHP_EOL;
    print 'Options:' . PHP_EOL;

    foreach ($help as $option => $description) {
        $whitespace = str_repeat(' ', $maxLen - strlen($option) + 2);
        print $option . $whitespace . $description . PHP_EOL;
    }

    print PHP_EOL;
}

/**
 * CLI interactive question
 *
 * @param string $question
 * @param array  $validAnswers
 *
 * @return bool
 */
function expectUserInput($question, array $validAnswers = [])
{
    print $question . ' : ';
    $stdin    = fopen('php://stdin', 'r');
    $response = fgetc($stdin);

    if (in_array(strtolower($response), $validAnswers)) {
        return true;
    }

    return false;
}

/**
 * This will update the script to newest version
 *
 * @param array $options
 * @param array $config
 */
function updateScript(array $options = [], array $config = [])
{
    if (strpos(basename($_SERVER['argv'][0]), '.php') !== false) {
        print 'It seems like this script haven\'t been installed - unable to update!' . PHP_EOL;
        exit(1);
    }

    requireRoot(); // Only root should be able to run this command

    if (!isset($options['force'])) {
        $status = printVersion($options, $config, true);
    } else {
        $status = false;
    }

    $branch = getBranch($options, $config);

    if ($status === false) {
        print 'See changes in commit history - https://github.com/jacklul/pihole-updatelists/commits/' . $branch . PHP_EOL . PHP_EOL;

        if (isset($options['yes']) || isset($options['force']) || expectUserInput('Update now? [Y/N]', ['y', 'yes'])) {
            $script_md5 = md5_file(__FILE__);

            print 'Downloading and running install script from "' . GITHUB_LINK_RAW . '/' . $branch . '/install.sh"...' . PHP_EOL . PHP_EOL;
            passthru('wget -nv -O - ' . GITHUB_LINK_RAW . '/' . $branch . '/install.sh | sudo bash /dev/stdin ' . $branch, $return);

            clearstatcache();

            if (file_exists('/var/tmp/pihole-updatelists.old') && $script_md5 != md5_file(__FILE__)) {
                print PHP_EOL . 'Use "' . basename(__FILE__) . ' --rollback" to return to the previous script version!' . PHP_EOL;
            }

            exit($return);
        } else {
            print 'Aborted by user.' . PHP_EOL;
        }
    }
}

/**
 * This will rollback the script to previous version
 *
 * @param array $options
 * @param array $config
 */
function rollbackScript(array $options = [], array $config = [])
{
    if (strpos(basename($_SERVER['argv'][0]), '.php') !== false) {
        print 'It seems like this script haven\'t been installed - unable to rollback!' . PHP_EOL;
        exit(1);
    }

    if (!file_exists('/var/tmp/pihole-updatelists.old')) {
        print 'Backup file does not exist, unable to rollback!' . PHP_EOL;
        exit(1);
    }

    if (md5_file('/usr/local/sbin/pihole-updatelists') === md5_file('/var/tmp/pihole-updatelists.old')) {
        print 'Current script checksum matches checksum of the backup, unable to rollback!' . PHP_EOL;
        exit(1);
    }

    requireRoot(); // Only root should be able to run this command

    if (isset($options['yes']) || expectUserInput('Are you sure you want to rollback? [Y/N]', ['y', 'yes'])) {
        if (rename('/var/tmp/pihole-updatelists.old', '/usr/local/sbin/pihole-updatelists') && chmod('/usr/local/sbin/pihole-updatelists', 0755)) {
            print 'Successfully rolled back the script!' . PHP_EOL;
            exit;
        }

        print 'Failed to rollback!' . PHP_EOL;
        exit(1);
    } else {
        print 'Aborted by user.' . PHP_EOL;
    }
}

/**
 * Check local and remote version and print it
 *
 * @param array $options
 * @param array $config
 *
 * @param bool  $return
 */
function printVersion(array $options = [], array $config = [], $return = false)
{
    global $remoteScript;

    $config['DEBUG'] === true && printDebugHeader($options, $config);
    $branch = getBranch($options, $config);

    print 'Git branch: ' . $branch . PHP_EOL;
    print 'Local checksum: ' . md5_file(__FILE__) . PHP_EOL;

    $updateCheck = isUpToDate($branch);
    if ($updateCheck === null) {
        print 'Failed to check remote script: ' . parseLastError() . PHP_EOL;
        exit(1);
    }

    print 'Remote checksum: ' . md5($remoteScript[$branch]) . PHP_EOL;

    if ($updateCheck === true) {
        print 'The script is up to date!' . PHP_EOL;

        if ($return === true) {
            return true;
        }

        exit;
    }

    print 'Update is available!' . PHP_EOL;

    if ($return === true) {
        return false;
    }
}

/**
 * Prints only the debug output and exits
 *
 * @param array $options
 * @param array $config
 *
 * @return void
 */
function showDebugPrint(array $options = [], array $config = [])
{
    printDebugHeader($options, $config);
    print 'Update check:' . PHP_EOL;
    printVersion($options, $config);
    exit;
}

/**
 * Validate important configuration variables
 *
 * @param array  $config
 * @param string $section
 *
 * @return array
 */
function validateConfig(array $config, $section = null)
{
    $configSection = $section ? ' (in configuration section "' . $section . '")' : '';

    if (isset($config['COMMENT'])) {
        if ($config['COMMENT'] === '') {
            printAndLog('Variable COMMENT must be a string at least 1 characters long!' . $configSection . PHP_EOL, 'ERROR');
            exit(1);
        }

        $config['COMMENT'] = trim($config['COMMENT']);
    }

    if (isset($config['GROUP_ID']) && !is_int($config['GROUP_ID'])) {
        printAndLog('Variable GROUP_ID must be a number!' . $configSection . PHP_EOL, 'ERROR');
        exit(1);
    }

    return $config;
}

/**
 * Load config file, if exists
 *
 * @param array $options
 *
 * @return array
 */
function loadConfig(array $options = [])
{
    // Default configuration
    $config = [
        'CONFIG_FILE'             => '/etc/pihole-updatelists.conf',
        'GRAVITY_DB'              => '/etc/pihole/gravity.db',
        'LOCK_FILE'               => '/var/lock/pihole-updatelists.lock',
        'PIHOLE_CMD'              => '/usr/local/bin/pihole',
        'LOG_FILE'                => '',
        'ADLISTS_URL'             => '',
        'WHITELIST_URL'           => '',
        'REGEX_WHITELIST_URL'     => '',
        'BLACKLIST_URL'           => '',
        'REGEX_BLACKLIST_URL'     => '',
        'COMMENT'                 => 'Managed by pihole-updatelists',
        'GROUP_ID'                => 0,
        'PERSISTENT_GROUP'        => true,
        'REQUIRE_COMMENT'         => true,
        'MIGRATION_MODE'          => 1,
        'GROUP_EXCLUSIVE'         => false,
        'UPDATE_GRAVITY'          => true,
        'VERBOSE'                 => false,
        'DEBUG'                   => false,
        'DOWNLOAD_TIMEOUT'        => 60,
        'IGNORE_DOWNLOAD_FAILURE' => false,
        'GIT_BRANCH'              => 'master',
    ];

    // Default paths overrides when Entware is detected
    if (file_exists('/opt/etc/opkg.conf')) {
        $config = [
            'CONFIG_FILE' => '/opt/etc/pihole-updatelists.conf',
            'GRAVITY_DB'  => '/opt/etc/pihole/gravity.db',
            'LOCK_FILE'   => '/opt/var/lock/pihole-updatelists.lock',
            'PIHOLE_CMD'  => '/opt/bin/pihole',
        ] + $config;
    }

    if (isset($options['config'])) {
        if (!file_exists($options['config']) && !isset($options['env'])) {
            printAndLog('Invalid file: ' . $options['config'] . PHP_EOL, 'ERROR');
            exit(1);
        }

        $config['CONFIG_FILE'] = $options['config'];
    }

    if (file_exists($config['CONFIG_FILE'])) {
        $configFile = file_get_contents($config['CONFIG_FILE']);

        // Convert any hash-commented lines to semicolons
        $configFile = preg_replace('/^\s{0,}(#)(.*)$/m', ';$2', $configFile);

        $loadedConfig = @parse_ini_string($configFile, true, INI_SCANNER_TYPED);
        if ($loadedConfig === false) {
            printAndLog('Failed to load configuration file: ' . parseLastError() . PHP_EOL, 'ERROR');
            exit(1);
        }

        unset($loadedConfig['CONFIG_FILE']);

        $config = array_merge($config, $loadedConfig);

        foreach ($config as $var => $val) {
            if (is_array($val)) {
                $sectionName = strtoupper($var);

                if (!isset($config['CONFIG_SECTIONS']) || !is_array($config['CONFIG_SECTIONS'])) {
                    $config['CONFIG_SECTIONS'] = [];
                }

                $config['CONFIG_SECTIONS'][$sectionName] = validateConfig(processConfigSection($val), $sectionName);

                unset($config[$var]);
            }
        }
    }

    if (isset($options['env'])) {
        $config = loadConfigFromEnvironment($config);
    }

    $config = validateConfig($config);

    if (isset($options['no-gravity']) && $config['UPDATE_GRAVITY'] === true) {
        $config['UPDATE_GRAVITY'] = false;
    }

    if (isset($options['no-reload']) && $config['UPDATE_GRAVITY'] === false) {
        $config['UPDATE_GRAVITY'] = null;
    }

    if (isset($options['verbose'])) {
        $config['VERBOSE'] = true;
    }

    if (isset($options['debug'])) {
        $config['DEBUG'] = true;
    }

    return $config;
}

/**
 * Load supported environment config variables
 *
 * @param array $config
 *
 * @return array
 */
function loadConfigFromEnvironment(array $config)
{
    $renamedVariables = [ // These variables will be prefixed with "PHUL_"
        'CONFIG_FILE',
        'GRAVITY_DB',
        'LOCK_FILE',
        'PIHOLE_CMD',
        'LOG_FILE',
        'VERBOSE',
        'DEBUG',
        'GIT_BRANCH',
    ];

    foreach ($config as $var => $val) {
        if (in_array($var, $renamedVariables)) {
            $env = getenv('PHUL_' . $var);
        } else {
            $env = getenv($var);
        }

        if (!empty($env))
        {
            switch (gettype($val)) {
                case "int":
                case "integer":
                    $env = (int)$env;
                    break;
                case "float":
                    $env = (float)$env;
                    break;
                case "string":
                    $env = (string)$env;
                    break;
                case "bool":
                case "boolean":
                    $env = (bool)$env;
                    break;
            }

            $config[$var] = $env;
        }
    }

    return $config;
}

/**
 * Remove variables that are useless from section
 *
 * @param array $section
 *
 * @return array
 */
function processConfigSection(array $section)
{
    $filteredKeys = [
        'ADLISTS_URL',
        'WHITELIST_URL',
        'REGEX_WHITELIST_URL',
        'BLACKLIST_URL',
        'REGEX_BLACKLIST_URL',
        'COMMENT',
        'GROUP_ID',
        'PERSISTENT_GROUP',
        'GROUP_EXCLUSIVE',
        'IGNORE_DOWNLOAD_FAILURE',
    ];

    foreach ($section as $var => &$val) {
        if (!in_array($var, $filteredKeys)) {
            unset($section[$var]);
        }
    }

    return $section;
}

/**
 * Process sections in config, if any
 *
 * @param array $config
 *
 * @return array
 */
function processConfigSections(array $config)
{
    $defaultConfig         = processConfigSection($config);
    $defaultConfigFiltered = $defaultConfig;

    foreach ($defaultConfigFiltered as $var => $val) {
        if (substr($var, -4) === '_URL') {
            unset($defaultConfigFiltered[$var]);
        }
    }

    $sections = [];
    if (isset($config['CONFIG_SECTIONS'])) {
        $sections = $config['CONFIG_SECTIONS'];

        // Import unset values from main config
        foreach ($sections as &$section) {
            $section = array_merge($defaultConfigFiltered, $section);
        }
    }

    unset($config['CONFIG_SECTIONS']);
    $sections = ['DEFAULT' => $defaultConfig] + $sections;

    return $sections;
}

/**
 * Acquire process lock
 *
 * @param string $lockfile
 * @param bool   $debug
 *
 * @return resource
 */
function acquireLock($lockfile, $debug = false)
{
    if (empty($lockfile)) {
        printAndLog('Lock file not defined!' . PHP_EOL, 'ERROR');
        exit(1);
    }

    if ($lock = @fopen($lockfile, 'wb+')) {
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            printAndLog('Another process is already running!' . PHP_EOL, 'ERROR');
            exit(6);
        }

        $debug === true && printAndLog('Acquired process lock through file: ' . $lockfile . PHP_EOL, 'DEBUG');

        return $lock;
    }

    printAndLog('Unable to access path or lock file: ' . $lockfile . PHP_EOL, 'ERROR');
    exit(1);
}

/**
 * Shutdown related tasks
 *
 * @return void
 */
function shutdownCleanup()
{
    global $config, $lock;

    if ($config['DEBUG'] === true) {
        printAndLog('Releasing lock and removing lockfile: ' . $config['LOCK_FILE'] . PHP_EOL, 'DEBUG');
    }

    flock($lock, LOCK_UN) && fclose($lock) && unlink($config['LOCK_FILE']);
}

/**
 * Just print the header
 *
 * @return void
 */
function printHeader()
{
    $header[] = 'Pi-hole\'s Lists Updater by Jack\'lul';
    $header[] = GITHUB_LINK;
    $offset   = ' ';

    $maxLen = 0;
    foreach ($header as $string) {
        $strlen                      = strlen($string);
        $strlen > $maxLen && $maxLen = $strlen;
    }

    foreach ($header as &$string) {
        $strlen = strlen($string);

        if ($strlen < $maxLen) {
            $diff = $maxLen - $strlen;
            $addL = ceil($diff / 2);
            $addR = $diff - $addL;

            $string = str_repeat(' ', (int) $addL) . $string . str_repeat(' ', (int) $addR);
        }

        $string = $offset . $string;
    }
    unset($string);

    printAndLog(trim($header[0]) . ' started' . PHP_EOL, 'INFO', true);
    print PHP_EOL . implode(PHP_EOL, $header) . PHP_EOL . PHP_EOL;
}

/**
 * Print debug information
 *
 * @param array $options
 * @param array $config
 */
function printDebugHeader(array $options, array $config)
{
    printAndLog('Checksum: ' . md5_file(__FILE__) . PHP_EOL, 'DEBUG');
    printAndLog('Git branch: ' . getBranch($options, $config) . PHP_EOL, 'DEBUG');
    printAndLog('OS: ' . php_uname() . PHP_EOL, 'DEBUG');
    printAndLog('PHP: ' . PHP_VERSION . (ZEND_THREAD_SAFE ? '' : ' NTS') . PHP_EOL, 'DEBUG');
    printAndLog('SQLite: ' . (new PDO('sqlite::memory:'))->query('select sqlite_version()')->fetch()[0] . PHP_EOL, 'DEBUG');
    printAndLog('cURL: ' . (function_exists('curl_version') ? curl_version()['version'] : 'Unavailable') . PHP_EOL, 'DEBUG');

    if (file_exists('/etc/pihole/versions')) {
        $versions = file_get_contents('/etc/pihole/versions');
        $versions = parse_ini_string($versions);

        $piholeVersions = [
            $versions['CORE_VERSION'],
            $versions['WEB_VERSION'],
            $versions['FTL_VERSION'],
        ];

        $piholeBranches = [
            $versions['CORE_BRANCH'],
            $versions['WEB_BRANCH'],
            $versions['FTL_BRANCH'],
        ];
    }

    if (empty($versions) && file_exists('/etc/pihole/localversions')) {
        $piholeVersions = file_get_contents('/etc/pihole/localversions');
        $piholeVersions = explode(' ', $piholeVersions);
    }

    if (empty($versions) && file_exists('/etc/pihole/localbranches')) {
        $piholeBranches = file_get_contents('/etc/pihole/localbranches');
        $piholeBranches = explode(' ', $piholeBranches);
    }

    if (
        isset($piholeVersions, $piholeBranches) &&
        $piholeVersions !== false && $piholeBranches !== false &&
        count($piholeVersions) === 3 && count($piholeBranches) === 3
    ) {
        printAndLog('Pi-hole Core: ' . $piholeVersions[0] . ' (' . $piholeBranches[0] . ')' . PHP_EOL, 'DEBUG');
        printAndLog('Pi-hole Web: ' . $piholeVersions[1] . ' (' . $piholeBranches[1] . ')' . PHP_EOL, 'DEBUG');
        printAndLog('Pi-hole FTL: ' . $piholeVersions[2] . ' (' . $piholeBranches[2] . ')' . PHP_EOL, 'DEBUG');
    } else {
        printAndLog('Pi-hole: Unavailable' . PHP_EOL, 'WARNING');
        incrementStat('warnings');
    }

    ob_start();
    var_dump($config);
    printAndLog('Configuration: ' . preg_replace('/=>\s+/', ' => ', ob_get_clean()), 'DEBUG');

    ob_start();
    var_dump($options);
    printAndLog('Options: ' . preg_replace('/=>\s+/', ' => ', ob_get_clean()), 'DEBUG');

    print PHP_EOL;
}

/**
 * Register PDO logging class
 *
 * @return void
 */
function registerPDOLogger()
{
    if (!class_exists('LoggedPDOStatement')) {
        class LoggedPDOStatement extends PDOStatement
        {
            private $queryParameters = [];
            private $parsedQuery     = '';

            public function bindValue($parameter, $value, $data_type = PDO::PARAM_STR): bool
            {
                $this->queryParameters[$parameter] = [
                    'value' => $value,
                    'type'  => $data_type,
                ];

                return parent::bindValue($parameter, $value, $data_type);
            }

            public function bindParam($parameter, &$variable, $data_type = PDO::PARAM_STR, $length = null, $driver_options = null): bool
            {
                $this->queryParameters[$parameter] = [
                    'value' => $variable,
                    'type'  => $data_type,
                ];

                return parent::bindParam($parameter, $variable, $data_type);
            }

            public function execute($input_parameters = null): bool
            {
                printAndLog('SQL Query: ' . $this->parseQuery() . PHP_EOL, 'DEBUG');

                return parent::execute($input_parameters);
            }

            private function parseQuery(): string
            {
                if (!empty($this->parsedQuery)) {
                    return $this->parsedQuery;
                }

                $query = $this->queryString;
                foreach ($this->queryParameters as $parameter => $data) {
                    switch ($data['type']) {
                        case PDO::PARAM_STR:
                            $value = '"' . $data['value'] . '"';
                            break;
                        case PDO::PARAM_INT:
                            $value = (string) $data['value'];
                            break;
                        case PDO::PARAM_BOOL:
                            $value = $data['value'] ? 'true' : 'false';
                            break;
                        default:
                            $value = null;
                    }

                    $query = str_replace($parameter, $value, $query);
                }

                return $this->parsedQuery = $query;
            }
        }
    }
}

/**
 * Open the database
 *
 * @param string $db_file
 * @param bool   $verbose
 * @param bool   $debug
 *
 * @return PDO
 */
function openDatabase($db_file, $verbose = true, $debug = false)
{
    $dbh = new PDO('sqlite:' . $db_file);
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $dbh->exec('PRAGMA foreign_keys = ON;'); // Require foreign key constraints

    if ($debug) {
        registerPDOLogger();
        $dbh->setAttribute(PDO::ATTR_STATEMENT_CLASS, ['LoggedPDOStatement']);
    }

    if ($verbose) {
        clearstatcache();
        printAndLog('Opened gravity database: ' . $db_file . ' (' . formatBytes(filesize($db_file)) . ')' . PHP_EOL);
    }

    return $dbh;
}

/**
 * Convert text files from one-entry-per-line to array
 *
 * @param string $text
 *
 * @return array
 *
 * @noinspection OnlyWritesOnParameterInspection
 */
function textToArray($text)
{
    global $comments;

    $array    = preg_split('/\r\n|\r|\n/', $text);
    $comments = [];

    foreach ($array as $var => &$val) {
        // Ignore empty lines and those with only a comment
        if (empty($val) || strpos(trim($val), '#') === 0 || strpos(trim($val), '=') === 0) {
            unset($array[$var]);
            continue;
        }

        $comment = '';

        // Extract value from lines ending with comment
        if (preg_match('/^(.*)\s+#\s*(\S.*)$/U', $val, $matches)) {
            list(, $val, $comment) = $matches;
        }

        $val                                = trim($val);
        !empty($comment) && $comments[$val] = trim($comment);
    }
    unset($val);

    return array_values($array);
}

/**
 * Checks if a given entry is a single adlist by checking if the first entry in the given $content is a domain
 * instead of an URL
 *
 * @param string $content
 *
 * @return boolean
 */
function isSingleAdlist($content) {
    $list = textToArray($content);

    if (!empty($list)) {
        $row_content = explode(' ', $list[0]);

        return filter_var($row_content[sizeof($row_content) - 1], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
    }

    return false;
}

/**
 * Parse last error from error_get_last()
 *
 * @param string $default
 *
 * @return string
 */
function parseLastError($default = 'Unknown error')
{
    global $httpClient, $customError;

    if (!empty($customError)) {
        $returnCustom = $customError;
        $customError  = '';

        return $returnCustom;
    }

    $lastError = error_get_last();

    if (is_object($httpClient)) {
        $lastHttpError = $httpClient->error();

        if (empty($lastError) && !empty($lastHttpError)) {
            $lastError['message'] = $lastHttpError;
        }
    }

    return preg_replace('/file_get_contents(.*): /U', '', trim($lastError['message'] ?? $default)) . (isset($lastError['line']) ? ' (' . $lastError['line'] . ')' : '');
}

/**
 * @param int $bytes
 * @param int $precision
 *
 * @return string
 */
function formatBytes($bytes, $precision = 2)
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];

    $bytes = max($bytes, 0);
    $pow   = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow   = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));

    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Increment values on $stat array
 *
 * @param string $name
 * @param string $deduplication
 *
 * @return void
 */
function incrementStat($name, $deduplication = null)
{
    global $stat;

    if (!isset($stat[$name])) {
        $stat[$name] = 0;
    }

    if ($deduplication !== null) {
        if (!isset($stat['list'][$name]) || !is_array($stat['list'][$name])) {
            if (!isset($stat['list']) || !is_array($stat['list'])) {
                $stat['list'] = [];
            }

            $stat['list'][$name] = [];
        }

        if (in_array($deduplication, $stat['list'][$name], true)) {
            return;
        }

        $stat['list'][$name][] = $deduplication;
    }

    $stat[$name]++;
}

/**
 * Print summary after processing single list (to be used after 'Processing...' message)
 *
 * @param array $statData
 * @param bool  $noSpace
 *
 * @return void
 */
function printOperationSummary(array $statData, $noSpace = false)
{
    $summary = [];

    $statData['exists'] > 0 && $summary[]   = $statData['exists'] . ' exists';
    $statData['ignored'] > 0 && $summary[]  = $statData['ignored'] . ' ignored';
    $statData['inserted'] > 0 && $summary[] = $statData['inserted'] . ' inserted';
    $statData['enabled'] > 0 && $summary[]  = $statData['enabled'] . ' enabled';
    $statData['disabled'] > 0 && $summary[] = $statData['disabled'] . ' disabled';
    $statData['invalid'] > 0 && $summary[]  = $statData['invalid'] . ' invalid';
    $statData['conflict'] > 0 && $summary[] = $statData['conflict'] . ' conflicts';
    $statData['migrated'] > 0 && $summary[] = $statData['migrated'] . ' migrated';

    if (!empty($summary)) {
        printAndLog(($noSpace === false ? ' ' : 'Summary: ') . implode(', ', $summary) . PHP_EOL);
    }
}

/**
 * Helper function that checks if comment field matches when required
 *
 * @param array  $array
 * @param string $comment
 * @param bool   $require_comment
 *
 * @return bool
 */
function checkIfTouchable($array, $comment, $require_comment = true)
{
    return $require_comment === false || (!empty($comment) && strpos($array['comment'] ?? '', $comment) !== false);
};

/** PROCEDURAL CODE STARTS HERE */
$startTime   = microtime(true);
$customError = '';
checkDependencies(); // Check script requirements
$options        = parseOptions(); // Parse options
$config         = loadConfig($options); // Load config and process variables
$configSections = processConfigSections($config); // Process sections

// Make sure we have at least one remote URL set
$remoteListsAreSet = false;
foreach ($configSections as $configSectionName => $configSectionData) {
    $sectionHasList = false;

    foreach ($configSectionData as $var => $val) {
        if (substr($var, -4) === '_URL' && !empty($val)) {
            $remoteListsAreSet = true;
            $sectionHasList    = true;

            break;
        }
    }

    if ($sectionHasList === false) {
        $configSections[$configSectionName]['SECTION_IGNORED'] = true;
    }
}

// Exception handler, always log detailed information
set_exception_handler(
    function (Throwable $e) use (&$config) {
        if ($config['DEBUG'] === false) {
            print 'Exception: ' . $e->getMessage() . PHP_EOL;
        }

        printAndLog($e . PHP_EOL, 'ERROR', $config['DEBUG'] === false);
        exit(1);
    }
);

$lock = acquireLock($config['LOCK_FILE'], $config['DEBUG']); // Make sure this is the only instance
register_shutdown_function('shutdownCleanup'); // Cleanup when script finishes

// Handle script interruption / termination
if (function_exists('pcntl_signal')) {
    declare (ticks = 1);

    function signalHandler($signo)
    {
        $definedConstants = get_defined_constants(true);
        $signame          = null;

        if (isset($definedConstants['pcntl'])) {
            foreach ($definedConstants['pcntl'] as $name => $num) {
                if ($num === $signo && strpos($name, 'SIG') === 0 && $name[3] !== '_') {
                    $signame = $name;
                }
            }
        }

        printAndLog(PHP_EOL . 'Interrupted by ' . ($signame ?? $signo) . PHP_EOL, 'NOTICE');
        exit(130);
    }

    pcntl_signal(SIGHUP, 'signalHandler');
    pcntl_signal(SIGTERM, 'signalHandler');
    pcntl_signal(SIGINT, 'signalHandler');
}

// This array holds stats data
$stat = [
    'errors'   => 0,
    'warnings' => 0,
    'exists'   => 0,
    'ignored'  => 0,
    'inserted' => 0,
    'enabled'  => 0,
    'disabled' => 0,
    'invalid'  => 0,
    'conflict' => 0,
    'migrated' => 0,
];

printHeader(); // Hi
checkOptionalDependencies(); // Check for optional stuff

// Show initial debug messages
$config['DEBUG'] === true && printDebugHeader($options, $config);

// Show deprecated/removed options messages
$deprecatedAndRemovedOptions      = ['VACUUM_DATABASE'];
$deprecatedAndRemovedOptionsFound = false;
foreach ($config as $option => $value) {
    if (in_array($option, $deprecatedAndRemovedOptions)) {
        printAndLog('Configuration option ' . $option . ' has been removed.' . PHP_EOL, 'WARNING');
        incrementStat('warnings');
        $deprecatedAndRemovedOptionsFound = true;
    }
}
$deprecatedAndRemovedOptionsFound && print PHP_EOL;

// Open the database
$dbh = openDatabase($config['GRAVITY_DB'], true, $config['DEBUG']);

print PHP_EOL;

// Initialize http client
$httpOptions = [
    'timeout'    => $config['DOWNLOAD_TIMEOUT'],
    'user_agent' => 'Pi-hole\'s Lists Updater (' . preg_replace('#^https?://#', '', rtrim(GITHUB_LINK, '/')) . ')',
];
$httpClient = createHttpClient($httpOptions);

// Iterate config sections
foreach ($configSections as $configSectionName => $configSectionData) {
    if (isset($configSectionData['SECTION_IGNORED']) && $configSectionData['SECTION_IGNORED'] === true) {
        continue;
    }

    if (count($configSections) > 1) {
        printAndLog('Executing using configuration section "' . $configSectionName . '"...' . PHP_EOL . PHP_EOL, 'INFO');
    }

    // Make sure group exists
    if (($absoluteGroupId = abs($configSectionData['GROUP_ID'])) > 0) {
        $sth = $dbh->prepare('SELECT `id` FROM `group` WHERE `id` = :id');
        $sth->bindParam(':id', $absoluteGroupId, PDO::PARAM_INT);

        if ($sth->execute() && $sth->fetch(PDO::FETCH_ASSOC) === false) {
            printAndLog('Group with ID = ' . $absoluteGroupId . ' does not exist!' . ' (skipped configuration section "' . $configSectionName . '")' . PHP_EOL . PHP_EOL, 'ERROR');
            incrementStat('errors');
            continue;
        }
    }

    // Fetch ADLISTS
    if (!empty($configSectionData['ADLISTS_URL'])) {
        $multipleLists = false;
        $statCopy      = [
            'exists'   => $stat['exists'],
            'ignored'  => $stat['ignored'],
            'inserted' => $stat['inserted'],
            'enabled'  => $stat['enabled'],
            'disabled' => $stat['disabled'],
            'invalid'  => $stat['invalid'],
            'conflict' => $stat['conflict'],
            'migrated' => $stat['migrated'],
        ];
        $summaryBuffer = [];

        // Fetch all adlists
        $adlistsAll = [];
        if (($sth = $dbh->prepare('SELECT * FROM `adlist`'))->execute()) {
            $adlistsAll = $sth->fetchAll(PDO::FETCH_ASSOC);

            $tmp = [];
            foreach ($adlistsAll as $key => $value) {
                $tmp[$value['id']] = $value;
            }

            $adlistsAll = $tmp;
            unset($tmp);
        }

        // Fetch adlist groups
        $adlistsGroupsAll = [];
        if (($sth = $dbh->prepare('SELECT * FROM `adlist_by_group`'))->execute()) {
            $adlistsGroupsAll = $sth->fetchAll(PDO::FETCH_ASSOC);

            $tmp = [];
            foreach ($adlistsAll as $adlist) {
                $tmp[$adlist['id']] = [];
            }

            foreach ($adlistsGroupsAll as $key => $value) {
                if (!isset($tmp[$value['adlist_id']]) || !is_array($tmp[$value['adlist_id']])) {
                    $tmp[$value['adlist_id']] = [];
                }

                $tmp[$value['adlist_id']][] = (int) $value['group_id'];
            }

            $adlistsGroupsAll = $tmp;
            unset($tmp);
        }

        if (preg_match('/\s+/', trim($configSectionData['ADLISTS_URL']))) {
            $adlistsUrl    = preg_split('/\s+/', $configSectionData['ADLISTS_URL']);
            $multipleLists = true;

            $contents = '';
            foreach ($adlistsUrl as $url) {
                if (!empty($url)) {
                    printAndLog('Fetching ADLISTS from \'' . $url . '\'...');

                    $listContents = @fetchFileContents($url, $httpOptions);

                    if (isSingleAdlist($listContents)) {
                        $listContents = $url;
                    }

                    if ($listContents !== false) {
                        printAndLog(' done' . PHP_EOL);

                        $contents .= PHP_EOL . $listContents;
                    } else {
                        if ($configSectionData['IGNORE_DOWNLOAD_FAILURE'] === false) {
                            printAndLog(' ' . parseLastError() . PHP_EOL, 'ERROR');

                            incrementStat('errors');
                            $contents = false;
                            break;
                        } else {
                            printAndLog(' ' . parseLastError() . PHP_EOL, 'WARNING');
                            incrementStat('warnings');
                        }
                    }
                }
            }

            $contents !== false && printAndLog('Merging multiple lists...');
        } else {
            printAndLog('Fetching ADLISTS from \'' . $configSectionData['ADLISTS_URL'] . '\'...');

            $contents = @fetchFileContents($configSectionData['ADLISTS_URL'], $httpOptions);
        }

        if ($contents !== false) {
            $adlists = textToArray($contents);
            printAndLog(' done (' . count($adlists) . ' entries)' . PHP_EOL);

            printAndLog('Processing...' . ($config['VERBOSE'] === true || $config['DEBUG'] === true ? PHP_EOL : ''));
            $dbh->beginTransaction();

            // Get enabled adlists managed by this script from the DB
            $sql = 'SELECT * FROM `adlist` WHERE `enabled` = 1';

            if ($config['REQUIRE_COMMENT'] === true) {
                $sth = $dbh->prepare($sql .= ' AND `comment` LIKE :comment');
                $sth->bindValue(':comment', '%' . $configSectionData['COMMENT'] . '%', PDO::PARAM_STR);
            } else {
                $sth = $dbh->prepare($sql);
            }

            $enabledLists = [];
            if ($sth->execute()) {
                foreach ($sth->fetchAll(PDO::FETCH_ASSOC) as $adlist) {
                    $enabledLists[$adlist['id']] = $adlist['address'];
                }
            }

            // Pull entries assigned to this group ID
            if ($configSectionData['GROUP_EXCLUSIVE'] === true) {
                $sth = $dbh->prepare('SELECT * FROM `adlist` LEFT JOIN `adlist_by_group` ON `adlist`.`id` = `adlist_by_group`.`adlist_id` WHERE `adlist`.`enabled` = 1 AND `adlist_by_group`.`group_id` = :group_id');
                $sth->bindValue(':group_id', $absoluteGroupId, PDO::PARAM_INT);

                if ($sth->execute()) {
                    foreach ($sth->fetchAll(PDO::FETCH_ASSOC) as $adlist) {
                        if (!isset($enabledLists[$adlist['id']])) {
                            $enabledLists[$adlist['id']] = $adlist['address'];
                        }
                    }
                }
            }

            // Entries that no longer exist in remote list
            $removedLists = array_diff($enabledLists, $adlists);

            foreach ($removedLists as $id => $address) {
                $allConfigurationsGroups = [];
                foreach ($configSections as $testConfigSectionName => $testConfigSectionData) {
                    $allConfigurationsGroups[] = abs($testConfigSectionData['GROUP_ID']);
                }

                $foreignGroups = [];
                foreach ($adlistsGroupsAll[$id] as $groupId) {
                    if (!in_array($groupId, $allConfigurationsGroups) && ($groupId !== 0 || $configSectionData['GROUP_ID'] < 0)) {
                        $foreignGroups[] = $groupId;
                    }
                }

                if (checkIfTouchable($adlistsAll[$id], $configSectionData['COMMENT'], $config['REQUIRE_COMMENT'])) {
                    $removed = 0;

                    // Remove from the set group
                    if ($absoluteGroupId > 0) {
                        $sth = $dbh->prepare('DELETE FROM `adlist_by_group` WHERE `adlist_id` = :adlist_id AND group_id = :group_id');
                        $sth->bindParam(':adlist_id', $id, PDO::PARAM_INT);
                        $sth->bindValue(':group_id', $absoluteGroupId, PDO::PARAM_INT);
                        $sth->execute();
                        $removed += $sth->rowCount();
                    }

                    // Remove from the default group
                    if ($configSectionData['GROUP_ID'] >= 0) {
                        $sth = $dbh->prepare('DELETE FROM `adlist_by_group` WHERE adlist_id = :adlist_id AND group_id = :group_id');
                        $sth->bindParam(':adlist_id', $id, PDO::PARAM_INT);
                        $sth->bindValue(':group_id', 0, PDO::PARAM_INT);
                        $sth->execute();
                        $removed += $sth->rowCount();
                    }

                    if ($removed > 0) {
                        $config['VERBOSE'] === true && printAndLog('Disabled: ' . $address . PHP_EOL);
                        incrementStat('disabled');
                    }
                }

                // Disable entry when it's touchable and no user groups are assigned
                if (count($foreignGroups) === 0) {
                    foreach ($configSections as $testConfigSectionName => $testConfigSectionData) {
                        if (checkIfTouchable($adlistsAll[$id], $testConfigSectionData['COMMENT'], $config['REQUIRE_COMMENT'])) {
                            $sth = $dbh->prepare('UPDATE `adlist` SET `enabled` = 0 WHERE `id` = :id');
                            $sth->bindParam(':id', $id, PDO::PARAM_INT);

                            if ($sth->execute()) {
                                $adlistsAll[$id]['enabled'] = false;
                            }

                            break;
                        }
                    }
                }
            }

            // Helper function to check whenever adlist already exists
            $checkAdlistExists = static function ($address) use (&$adlistsAll) {
                $result = array_filter(
                    $adlistsAll,
                    static function ($array) use ($address) {
                        return isset($array['address']) && $array['address'] === $address;
                    }
                );

                return count($result) === 1 ? array_values($result)[0] : false;
            };

            foreach ($adlists as $address) {
                if (!filter_var($address, FILTER_VALIDATE_URL)) {
                    if ($config['VERBOSE'] === true) {
                        printAndLog('Invalid: ' . $address . PHP_EOL, 'NOTICE');
                    } else {
                        $summaryBuffer['invalid'][] = $address;
                    }

                    incrementStat('invalid');
                    continue;
                }

                $adlistUrl = $checkAdlistExists($address);
                if ($adlistUrl === false) {
                    // Add entry if it doesn't exist
                    $sth = $dbh->prepare('INSERT INTO `adlist` (address, enabled, comment) VALUES (:address, 1, :comment)');
                    $sth->bindParam(':address', $address, PDO::PARAM_STR);

                    $comment = $configSectionData['COMMENT'];
                    if (isset($comments[$address])) {
                        $comment = $comments[$address] . ($comment !== '' ? ' | ' . $comment : '');
                    }
                    $sth->bindParam(':comment', $comment, PDO::PARAM_STR);

                    if ($sth->execute()) {
                        $lastInsertId = $dbh->lastInsertId();

                        // Insert this adlist into cached list of all adlists to prevent future duplicate errors
                        $adlistsAll[$lastInsertId] = [
                            'id'      => $lastInsertId,
                            'address' => $address,
                            'enabled' => true,
                            'comment' => $comment,
                        ];

                        if ($absoluteGroupId > 0) {
                            // Add to the specified group
                            $sth = $dbh->prepare('INSERT OR IGNORE INTO `adlist_by_group` (adlist_id, group_id) VALUES (:adlist_id, :group_id)');
                            $sth->bindParam(':adlist_id', $lastInsertId, PDO::PARAM_INT);
                            $sth->bindParam(':group_id', $absoluteGroupId, PDO::PARAM_INT);
                            $sth->execute();

                            if ($configSectionData['GROUP_ID'] < 0) {
                                // Remove from the default group
                                $sth = $dbh->prepare('DELETE FROM `adlist_by_group` WHERE adlist_id = :adlist_id AND group_id = :group_id');
                                $sth->bindParam(':adlist_id', $lastInsertId, PDO::PARAM_INT);
                                $sth->bindValue(':group_id', 0, PDO::PARAM_INT);
                                $sth->execute();
                            }
                        }

                        $config['VERBOSE'] === true && printAndLog('Inserted: ' . $address . PHP_EOL);
                        incrementStat('inserted');
                    }
                } else {
                    $isTouchable          = checkIfTouchable($adlistUrl, $configSectionData['COMMENT'], $config['REQUIRE_COMMENT']);
                    $adlistUrl['enabled'] = (bool) $adlistUrl['enabled'] === true;

                    // Check if entry has any groups assigned
                    $hasGroups = true;
                    if ($configSectionData['PERSISTENT_GROUP'] === false) {
                        $sth = $dbh->prepare('SELECT * FROM `adlist_by_group` WHERE `adlist_by_group`.`adlist_id` = :adlist_id');
                        $sth->bindValue(':adlist_id', $adlistUrl['id'], PDO::PARAM_INT);
                        if ($sth->execute() && empty($sth->fetchAll(PDO::FETCH_ASSOC))) {
                            $hasGroups = false;
                        }
                    }

                    // Enable existing entry but only if it's managed by this script
                    if ($adlistUrl['enabled'] !== true && $isTouchable === true) {
                        $sth = $dbh->prepare('UPDATE `adlist` SET `enabled` = 1 WHERE `id` = :id');
                        $sth->bindParam(':id', $adlistUrl['id'], PDO::PARAM_INT);

                        if ($sth->execute()) {
                            $adlistsAll[$adlistUrl['id']]['enabled'] = true;

                            $config['VERBOSE'] === true && printAndLog('Enabled: ' . $address . PHP_EOL);
                            incrementStat('enabled');
                        }
                    } elseif ($adlistUrl['enabled'] !== false && $isTouchable === true) {
                        $config['VERBOSE'] === true && printAndLog('Exists: ' . $address . PHP_EOL);
                        incrementStat('exists');
                    } elseif ($isTouchable === false) {
                        // Migration in this context means replacing comment field if current one is also managed by the script
                        $canBeMigrated = false;

                        if ((int)$config['MIGRATION_MODE'] > 0 && $adlistUrl['enabled'] === false) {
                            foreach ($configSections as $testConfigSectionName => $testConfigSectionData) {
                                if (checkIfTouchable($adlistUrl, $testConfigSectionData['COMMENT'], $config['REQUIRE_COMMENT'])) {
                                    $canBeMigrated = true;
                                    break;
                                }
                            }

                            if ($canBeMigrated) {
                                if ((int)$config['MIGRATION_MODE'] === 1) {
                                    $newComment = str_replace($testConfigSectionData['COMMENT'], $configSectionData['COMMENT'], $adlistUrl['comment']);
                                } elseif ((int)$config['MIGRATION_MODE'] === 2) {
                                    $newComment = $adlistUrl['comment'] . ' | ' . $configSectionData['COMMENT'];
                                } else {
                                    throw new RuntimeException('Invalid migration mode specified');
                                }

                                $sth = $dbh->prepare('UPDATE `adlist` SET `enabled` = 1, `comment` = :comment WHERE `id` = :id');
                                $sth->bindParam(':id', $adlistUrl['id'], PDO::PARAM_INT);
                                $sth->bindParam(':comment', $newComment, PDO::PARAM_STR);

                                if ($sth->execute()) {
                                    $adlistsAll[$adlistUrl['id']]['enabled'] = true;
                                    $adlistsAll[$adlistUrl['id']]['comment'] = $newComment;

                                    $oldGroupId = abs($testConfigSectionData['GROUP_ID']);

                                    $sth = $dbh->prepare('DELETE FROM `adlist_by_group` WHERE `adlist_id` = :adlist_id AND `group_id` = :group_id');
                                    $sth->bindParam(':adlist_id', $adlistUrl['id'], PDO::PARAM_INT);
                                    $sth->bindParam(':group_id', $oldGroupId, PDO::PARAM_INT);
                                    $sth->execute();

                                    if (($key = array_search($oldGroupId, $adlistsGroupsAll[$adlistUrl['id']])) !== false) {
                                        unset($adlistsGroupsAll[$adlistUrl['id']][$key]);
                                    }

                                    $config['VERBOSE'] === true && printAndLog('Migrated: ' . $address . PHP_EOL);
                                    incrementStat('migrated');
                                } else {
                                    printAndLog('Failed to migrate: ' . $address . PHP_EOL);
                                    incrementStat('errors');
                                }
                            }
                        }

                        if ($canBeMigrated === false) {
                            $config['VERBOSE'] === true && printAndLog('Ignored: ' . $address . PHP_EOL);
                            incrementStat('ignored');
                        }
                    }

                    if ($configSectionData['PERSISTENT_GROUP'] === true || $hasGroups == false) {
                        // (Re)Add to the specified group when not added
                        if (
                            $absoluteGroupId > 0 &&
                            (isset($adlistsGroupsAll[$adlistUrl['id']]) && !in_array($absoluteGroupId, $adlistsGroupsAll[$adlistUrl['id']], true))
                        ) {
                            $sth = $dbh->prepare('INSERT OR IGNORE INTO `adlist_by_group` (adlist_id, group_id) VALUES (:adlist_id, :group_id)');
                            $sth->bindParam(':adlist_id', $adlistUrl['id'], PDO::PARAM_INT);
                            $sth->bindParam(':group_id', $absoluteGroupId, PDO::PARAM_INT);
                            $sth->execute();

                            $config['VERBOSE'] === true && $sth->rowCount() && printAndLog('Added \'' . $address . '\' to the group with ID = ' . $absoluteGroupId . PHP_EOL);
                        }

                        // (Re)Add to the default group when not added
                        if (
                            $configSectionData['GROUP_ID'] >= 0 &&
                            (isset($adlistsGroupsAll[$adlistUrl['id']]) && !in_array(0, $adlistsGroupsAll[$adlistUrl['id']], true))
                        ) {
                            $sth = $dbh->prepare('INSERT OR IGNORE INTO `adlist_by_group` (adlist_id, group_id) VALUES (:adlist_id, :group_id)');
                            $sth->bindParam(':adlist_id', $adlistUrl['id'], PDO::PARAM_INT);
                            $sth->bindValue(':group_id', 0, PDO::PARAM_INT);
                            $sth->execute();

                            $config['VERBOSE'] === true && $sth->rowCount() && printAndLog('Added \'' . $address . '\' to the default group' . PHP_EOL);
                        }
                    }
                }
            }

            $dbh->commit();

            foreach ($statCopy as $var => $val) {
                $statCopy[$var] = $stat[$var] - $statCopy[$var];
            }

            printOperationSummary($statCopy, ($config['VERBOSE'] === true || $config['DEBUG'] === true));

            if ($config['VERBOSE'] === false) {
                if (isset($summaryBuffer['invalid'])) {
                    printAndLog('List of invalid entries:' . PHP_EOL . ' ' . implode(PHP_EOL . ' ', $summaryBuffer['invalid']) . PHP_EOL, 'NOTICE');
                }
            }
        } else {
            if ($multipleLists) {
                printAndLog('One of the lists failed to download, operation aborted!' . PHP_EOL, 'NOTICE');
            } else {
                printAndLog(' ' . parseLastError() . PHP_EOL, 'ERROR');
                incrementStat('errors');
            }
        }

        print PHP_EOL;
    }

    // This array binds type of list to 'domainlist' table 'type' field, thanks to this we can use foreach loop instead of duplicating code
    $domainListTypes = [
        'WHITELIST'       => 0,
        'REGEX_WHITELIST' => 2,
        'BLACKLIST'       => 1,
        'REGEX_BLACKLIST' => 3,
    ];

    // Fetch all domains from domainlist
    $domainsAll = [];
    if (($sth = $dbh->prepare('SELECT * FROM `domainlist`'))->execute()) {
        $domainsAll = $sth->fetchAll(PDO::FETCH_ASSOC);

        $tmp = [];
        foreach ($domainsAll as $key => $value) {
            $tmp[$value['id']] = $value;
        }

        $domainsAll = $tmp;
        unset($tmp);
    }

    // Fetch domainslists entries groups
    $domainsGroupsAll = [];
    if (($sth = $dbh->prepare('SELECT * FROM `domainlist_by_group`'))->execute()) {
        $domainsGroupsAll = $sth->fetchAll(PDO::FETCH_ASSOC);

        $tmp = [];
        foreach ($domainsAll as $domain) {
            $tmp[$domain['id']] = [];
        }

        foreach ($domainsGroupsAll as $key => $value) {
            if (!isset($tmp[$value['domainlist_id']]) || !is_array($tmp[$value['domainlist_id']])) {
                $tmp[$value['domainlist_id']] = [];
            }

            $tmp[$value['domainlist_id']][] = $value['group_id'];
        }

        $domainsGroupsAll = $tmp;
        unset($tmp);
    }

    // Instead of calling this function multiple times later we save the result here...
    $canConvertIdn = extension_loaded('intl');

    // Helper function to check whenever domain with specific type already exists
    $checkDomainExists = static function ($domain, $type) use (&$domainsAll) {
        $result = array_filter(
            $domainsAll,
            static function ($array) use ($domain, $type) {
                return isset($array['domain']) && $array['domain'] === $domain && (int)$array['type'] === (int)$type;
            }
        );

        return count($result) === 1 ? array_values($result)[0] : false;
    };

    // Fetch DOMAINLISTS
    foreach ($domainListTypes as $typeName => $typeId) {
        $url_key = $typeName . '_URL';

        if (!empty($configSectionData[$url_key])) {
            $multipleLists = false;
            $statCopy      = [
                'exists'   => $stat['exists'],
                'ignored'  => $stat['ignored'],
                'inserted' => $stat['inserted'],
                'enabled'  => $stat['enabled'],
                'disabled' => $stat['disabled'],
                'invalid'  => $stat['invalid'],
                'conflict' => $stat['conflict'],
                'migrated' => $stat['migrated'],
            ];
            $summaryBuffer = [];

            if (preg_match('/\s+/', trim($configSectionData[$url_key]))) {
                $domainlistUrl = preg_split('/\s+/', $configSectionData[$url_key]);
                $multipleLists = true;

                $contents = '';
                foreach ($domainlistUrl as $url) {
                    if (!empty($url)) {
                        printAndLog('Fetching ' . $typeName . ' from \'' . $url . '\'...');

                        $listContents = @fetchFileContents($url, $httpOptions);

                        if ($listContents !== false) {
                            printAndLog(' done' . PHP_EOL);

                            $contents .= PHP_EOL . $listContents;
                        } else {
                            if ($configSectionData['IGNORE_DOWNLOAD_FAILURE'] === false) {
                                printAndLog(' ' . parseLastError() . PHP_EOL, 'ERROR');

                                incrementStat('errors');
                                $contents = false;
                                break;
                            } else {
                                printAndLog(' ' . parseLastError() . PHP_EOL, 'WARNING');
                                incrementStat('warnings');
                            }
                        }
                    }
                }

                $contents !== false && printAndLog('Merging multiple lists...');
            } else {
                printAndLog('Fetching ' . $typeName . ' from \'' . $configSectionData[$url_key] . '\'...');

                $contents = @fetchFileContents($configSectionData[$url_key], $httpOptions);
            }

            if ($contents !== false) {
                $domainlist = textToArray($contents);
                printAndLog(' done (' . count($domainlist) . ' entries)' . PHP_EOL);

                printAndLog('Processing...' . ($config['VERBOSE'] === true || $config['DEBUG'] === true ? PHP_EOL : ''));
                $dbh->beginTransaction();

                // Get enabled domains of this type managed by this script from the DB
                $sql = 'SELECT * FROM `domainlist` WHERE `enabled` = 1 AND `type` = :type';

                if ($config['REQUIRE_COMMENT'] === false) {
                    $sth = $dbh->prepare($sql);
                } else {
                    $sth = $dbh->prepare($sql .= ' AND `comment` LIKE :comment');
                    $sth->bindValue(':comment', '%' . $configSectionData['COMMENT'] . '%', PDO::PARAM_STR);
                }

                $sth->bindParam(':type', $typeId, PDO::PARAM_INT);

                $enabledDomains = [];
                if ($sth->execute()) {
                    foreach ($sth->fetchAll(PDO::FETCH_ASSOC) as $domain) {
                        if (strpos($typeName, 'REGEX_') === false) {
                            $enabledDomains[$domain['id']] = strtolower($domain['domain']);
                        } else {
                            $enabledDomains[$domain['id']] = $domain['domain'];
                        }
                    }
                }

                // Pull entries assigned to this group ID
                if ($configSectionData['GROUP_EXCLUSIVE'] === true) {
                    $sth = $dbh->prepare('SELECT * FROM `domainlist` LEFT JOIN `domainlist_by_group` ON `domainlist`.`id` = `domainlist_by_group`.`domainlist_id` WHERE `domainlist`.`enabled` = 1 AND `domainlist`.`type` = :type AND `domainlist_by_group`.`group_id` = :group_id');

                    $sth->bindParam(':type', $typeId, PDO::PARAM_INT);
                    $sth->bindValue(':group_id', $absoluteGroupId, PDO::PARAM_INT);

                    if ($sth->execute()) {
                        foreach ($sth->fetchAll(PDO::FETCH_ASSOC) as $domain) {
                            if (!isset($enabledDomains[$domain['id']])) {
                                $enabledDomains[$domain['id']] = $domain['domain'];
                            }
                        }
                    }
                }

                // Process internationalized domains
                foreach ($domainlist as &$domain) {
                    if (strpos($typeName, 'REGEX_') === false) {
                        // Conversion code 'borrowed' from `scripts/pi-hole/php/groups.php` - 'add_domain'
                        if ($canConvertIdn) {
                            $idn_domain = false;

                            if (defined('INTL_IDNA_VARIANT_UTS46')) {
                                $idn_domain = @idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
                            }

                            if ($idn_domain === false && defined('INTL_IDNA_VARIANT_2003')) {
                                $idn_domain = @idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_2003);
                            }

                            if ($idn_domain !== false) {
                                $domain = $idn_domain;
                            }
                        }

                        $domain = strtolower($domain);
                    }
                }
                unset($domain);

                // Entries that no longer exist in remote list
                $removedDomains = array_diff($enabledDomains, $domainlist);

                foreach ($removedDomains as $id => $domain) {
                    $allConfigurationsGroups = [];
                    foreach ($configSections as $testConfigSectionName => $testConfigSectionData) {
                        $allConfigurationsGroups[] = abs($testConfigSectionData['GROUP_ID']);
                    }

                    $foreignGroups = [];
                    foreach ($domainsGroupsAll[$id] as $groupId) {
                        if (!in_array($groupId, $allConfigurationsGroups) && ($groupId !== 0 || $configSectionData['GROUP_ID'] < 0)) {
                            $foreignGroups[] = $groupId;
                        }
                    }

                    if (checkIfTouchable($domainsAll[$id], $configSectionData['COMMENT'], $config['REQUIRE_COMMENT'])) {
                        $removed = 0;

                        // Remove from the set group
                        if ($absoluteGroupId > 0) {
                            $sth = $dbh->prepare('DELETE FROM `domainlist_by_group` WHERE `domainlist_id` = :domainlist_id AND group_id = :group_id');
                            $sth->bindParam(':domainlist_id', $id, PDO::PARAM_INT);
                            $sth->bindValue(':group_id', $absoluteGroupId, PDO::PARAM_INT);
                            $sth->execute();
                            $removed += $sth->rowCount();
                        }

                        // Remove from the default group
                        if ($configSectionData['GROUP_ID'] >= 0) {
                            $sth = $dbh->prepare('DELETE FROM `domainlist_by_group` WHERE `domainlist_id` = :domainlist_id AND group_id = :group_id');
                            $sth->bindParam(':domainlist_id', $id, PDO::PARAM_INT);
                            $sth->bindValue(':group_id', 0, PDO::PARAM_INT);
                            $sth->execute();
                            $removed += $sth->rowCount();
                        }

                        if ($removed > 0) {
                            $config['VERBOSE'] === true && printAndLog('Disabled: ' . $domain . PHP_EOL);
                            incrementStat('disabled');
                        }
                    }

                    // Disable entry when it's touchable and no user groups are assigned
                    if (count($foreignGroups) === 0) {
                        foreach ($configSections as $testConfigSectionName => $testConfigSectionData) {
                            if (checkIfTouchable($domainsAll[$id], $testConfigSectionData['COMMENT'], $config['REQUIRE_COMMENT'])) {
                                $sth = $dbh->prepare('UPDATE `domainlist` SET `enabled` = 0 WHERE `id` = :id');
                                $sth->bindParam(':id', $id, PDO::PARAM_INT);

                                if ($sth->execute()) {
                                    $domainsAll[$id]['enabled'] = false;
                                }

                                break;
                            }
                        }
                    }
                }

                foreach ($domainlist as $domain) {
                    if (strpos($typeName, 'REGEX_') === false) {
                        // Check 'borrowed' from `scripts/pi-hole/php/groups.php` - 'add_domain'
                        if (filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                            if ($config['VERBOSE'] === true) {
                                printAndLog('Invalid: ' . $domain . PHP_EOL, 'NOTICE');
                            } else {
                                $summaryBuffer['invalid'][] = $domain;
                            }

                            incrementStat('invalid');
                            continue;
                        }
                    }

                    $domainlistDomain = $checkDomainExists($domain, $typeId);
                    if ($domainlistDomain === false) {
                        // Add entry if it doesn't exist
                        $sth = $dbh->prepare('INSERT INTO `domainlist` (domain, type, enabled, comment) VALUES (:domain, :type, 1, :comment)');
                        $sth->bindParam(':domain', $domain, PDO::PARAM_STR);
                        $sth->bindParam(':type', $typeId, PDO::PARAM_INT);

                        $comment = $configSectionData['COMMENT'];
                        if (isset($comments[$domain])) {
                            $comment = $comments[$domain] . ($comment !== '' ? ' | ' . $comment : '');
                        }
                        $sth->bindParam(':comment', $comment, PDO::PARAM_STR);

                        if ($sth->execute()) {
                            $lastInsertId = $dbh->lastInsertId();

                            // Insert this domain into cached list of all domains to prevent future duplicate errors
                            $domainsAll[$lastInsertId] = [
                                'id'      => $lastInsertId,
                                'domain'  => $domain,
                                'type'    => $typeId,
                                'enabled' => true,
                                'comment' => $comment,
                            ];

                            if ($absoluteGroupId > 0) {
                                // Add to the specified group
                                $sth = $dbh->prepare('INSERT OR IGNORE INTO `domainlist_by_group` (domainlist_id, group_id) VALUES (:domainlist_id, :group_id)');
                                $sth->bindParam(':domainlist_id', $lastInsertId, PDO::PARAM_INT);
                                $sth->bindParam(':group_id', $absoluteGroupId, PDO::PARAM_INT);
                                $sth->execute();

                                if ($configSectionData['GROUP_ID'] < 0) {
                                    // Remove from the default group
                                    $sth = $dbh->prepare('DELETE FROM `domainlist_by_group` WHERE domainlist_id = :domainlist_id AND group_id = :group_id');
                                    $sth->bindParam(':domainlist_id', $lastInsertId, PDO::PARAM_INT);
                                    $sth->bindValue(':group_id', 0, PDO::PARAM_INT);
                                    $sth->execute();
                                }
                            }

                            $config['VERBOSE'] === true && printAndLog('Inserted: ' . $domain . PHP_EOL);
                            incrementStat('inserted');
                        }
                    } else {
                        $isTouchable                 = checkIfTouchable($domainlistDomain, $configSectionData['COMMENT'], $config['REQUIRE_COMMENT']);
                        $domainlistDomain['enabled'] = (bool) $domainlistDomain['enabled'] === true;
                        $domainlistDomain['type']    = (int) $domainlistDomain['type'];

                        // Check if entry has any groups assigned
                        $hasGroups = true;
                        if ($configSectionData['PERSISTENT_GROUP'] === false) {
                            $sth = $dbh->prepare('SELECT * FROM `domainlist_by_group` WHERE `domainlist_by_group`.`domainlist_id` = :domainlist_id');
                            $sth->bindValue(':domainlist_id', $domainlistDomain['id'], PDO::PARAM_INT);
                            if ($sth->execute() && empty($sth->fetchAll(PDO::FETCH_ASSOC))) {
                                $hasGroups = false;
                            }
                        }

                        // Enable existing entry but only if it's managed by this script
                        if ($domainlistDomain['type'] === $typeId && $domainlistDomain['enabled'] === false && $isTouchable === true) {
                            $sth = $dbh->prepare('UPDATE `domainlist` SET `enabled` = 1 WHERE `id` = :id');
                            $sth->bindParam(':id', $domainlistDomain['id'], PDO::PARAM_INT);

                            if ($sth->execute()) {
                                $domainsAll[$domainlistDomain['id']]['enabled'] = true;

                                $config['VERBOSE'] === true && printAndLog('Enabled: ' . $domain . PHP_EOL);
                                incrementStat('enabled');
                            }
                        } elseif ($domainlistDomain['type'] !== $typeId) { // After adding 'type' to $checkDomainExists this should never be reached
                            $existsOnList = (array_search($domainlistDomain['type'], $domainListTypes, true) ?: 'type=' . $domainlistDomain['type']);

                            if ($config['VERBOSE'] === true) {
                                printAndLog('Conflict: ' . $domain . ' (' . $existsOnList . ')' . PHP_EOL, 'NOTICE');
                            } else {
                                $summaryBuffer['conflict'][$domain] = $existsOnList;
                            }

                            incrementStat('conflict', $domain);
                        } elseif ($domainlistDomain['enabled'] === true && $isTouchable === true) {
                            $config['VERBOSE'] === true && printAndLog('Exists: ' . $domain . PHP_EOL);
                            incrementStat('exists', $domain);
                        } elseif ($isTouchable === false) {
                            // Migration in this context means replacing comment field if current one is also managed by the script
                            $canBeMigrated = false;

                            if ((int)$config['MIGRATION_MODE'] > 0 && $domainlistDomain['enabled'] === false) {
                                foreach ($configSections as $testConfigSectionName => $testConfigSectionData) {
                                    if (checkIfTouchable($domainlistDomain, $testConfigSectionData['COMMENT'], $config['REQUIRE_COMMENT'])) {
                                        $canBeMigrated = true;
                                        break;
                                    }
                                }

                                if ($canBeMigrated) {
                                    if ((int)$config['MIGRATION_MODE'] === 1) {
                                        $newComment = str_replace($testConfigSectionData['COMMENT'], $configSectionData['COMMENT'], $domainlistDomain['comment']);
                                    } elseif ((int)$config['MIGRATION_MODE'] === 2) {
                                        $newComment = $domainlistDomain['comment'] . ' | ' . $configSectionData['COMMENT'];
                                    } else {
                                        throw new RuntimeException('Invalid migration mode specified');
                                    }

                                    $sth = $dbh->prepare('UPDATE `domainlist` SET `enabled` = 1, `comment` = :comment WHERE `id` = :id');
                                    $sth->bindParam(':id', $domainlistDomain['id'], PDO::PARAM_INT);
                                    $sth->bindParam(':comment', $newComment, PDO::PARAM_STR);

                                    if ($sth->execute()) {
                                        $domainsAll[$domainlistDomain['id']]['enabled'] = true;
                                        $domainsAll[$domainlistDomain['id']]['comment'] = $newComment;

                                        $oldGroupId = abs($testConfigSectionData['GROUP_ID']);

                                        $sth = $dbh->prepare('DELETE FROM `domainlist_by_group` WHERE `domainlist_id` = :domainlist_id AND `group_id` = :group_id');
                                        $sth->bindParam(':domainlist_id', $domainlistDomain['id'], PDO::PARAM_INT);
                                        $sth->bindParam(':group_id', $oldGroupId, PDO::PARAM_INT);
                                        $sth->execute();

                                        if (($key = array_search($oldGroupId, $domainsGroupsAll[$domainlistDomain['id']])) !== false) {
                                            unset($domainsGroupsAll[$domainlistDomain['id']][$key]);
                                        }

                                        $isTouchable = true;
                                        $config['VERBOSE'] === true && printAndLog('Migrated: ' . $domain . PHP_EOL);
                                        incrementStat('migrated');
                                    } else {
                                        printAndLog('Failed to migrate: ' . $domain . PHP_EOL);
                                        incrementStat('errors');
                                    }
                                }
                            }

                            if ($canBeMigrated === false) {
                                $config['VERBOSE'] === true && printAndLog('Ignored: ' . $domain . PHP_EOL);
                                incrementStat('ignored', $domain);
                            }
                        }

                        if ($isTouchable === true && ($configSectionData['PERSISTENT_GROUP'] === true || $hasGroups === false)) {
                            // (Re)Add to the specified group when not added
                            if (
                                $absoluteGroupId > 0 &&
                                (isset($domainsGroupsAll[$domainlistDomain['id']]) && !in_array($absoluteGroupId, $domainsGroupsAll[$domainlistDomain['id']], true))
                            ) {
                                $sth = $dbh->prepare('INSERT OR IGNORE INTO `domainlist_by_group` (domainlist_id, group_id) VALUES (:domainlist_id, :group_id)');
                                $sth->bindParam(':domainlist_id', $domainlistDomain['id'], PDO::PARAM_INT);
                                $sth->bindParam(':group_id', $absoluteGroupId, PDO::PARAM_INT);
                                $sth->execute();

                                $config['VERBOSE'] === true && $sth->rowCount() && printAndLog('Added \'' . $domain . '\' to the group with ID = ' . $absoluteGroupId . PHP_EOL);
                            }

                            // (Re)Add to the default group when not added
                            if (
                                $configSectionData['GROUP_ID'] >= 0 &&
                                (isset($domainsGroupsAll[$domainlistDomain['id']]) && !in_array(0, $domainsGroupsAll[$domainlistDomain['id']], true))
                            ) {
                                $sth = $dbh->prepare('INSERT OR IGNORE INTO `domainlist_by_group` (domainlist_id, group_id) VALUES (:domainlist_id, :group_id)');
                                $sth->bindParam(':domainlist_id', $domainlistDomain['id'], PDO::PARAM_INT);
                                $sth->bindValue(':group_id', 0, PDO::PARAM_INT);
                                $sth->execute();

                                $config['VERBOSE'] === true && $sth->rowCount() && printAndLog('Added \'' . $domain . '\' the default group' . PHP_EOL);
                            }
                        }
                    }
                }

                $dbh->commit();

                foreach ($statCopy as $var => $val) {
                    $statCopy[$var] = $stat[$var] - $statCopy[$var];
                }
                printOperationSummary($statCopy, ($config['VERBOSE'] === true || $config['DEBUG'] === true));

                if ($config['VERBOSE'] === false) {
                    if (isset($summaryBuffer['invalid'])) {
                        printAndLog('List of invalid entries:' . PHP_EOL . ' ' . implode(PHP_EOL . ' ', $summaryBuffer['invalid']) . PHP_EOL, 'NOTICE');
                    }

                    if (isset($summaryBuffer['conflict'])) {
                        foreach ($summaryBuffer['conflict'] as $duplicatedDomain => $onList) {
                            $summaryBuffer['conflict'][$duplicatedDomain] = $duplicatedDomain . ' (' . $onList . ')';
                        }

                        printAndLog('List of conflicting entries:' . PHP_EOL . ' ' . implode(PHP_EOL . ' ', $summaryBuffer['conflict']) . PHP_EOL, 'NOTICE');
                    }
                }
            } else {
                if ($multipleLists) {
                    printAndLog('One of the lists failed to download, operation aborted!' . PHP_EOL, 'NOTICE');
                } else {
                    printAndLog(' ' . parseLastError() . PHP_EOL, 'ERROR');
                    incrementStat('errors');
                }
            }

            print PHP_EOL;
        }
    }
}

if ($remoteListsAreSet === false) {
    printAndLog('No remote lists are set in the configuration - this is required for the script to do it\'s job!' . PHP_EOL . 'See README for instructions.' . PHP_EOL . PHP_EOL, 'ERROR');
    $stat['errors']++;
}

// Update gravity (run `pihole updateGravity`) or sends signal to pihole-FTL to reload lists
if ($config['UPDATE_GRAVITY'] === true) {
    $sth = $dbh = null; // Close any database handles

    if ($config['DEBUG'] === true) {
        printAndLog('Closed database handles.' . PHP_EOL, 'DEBUG');
    }

    $command = $config['PIHOLE_CMD'] . ' updateGravity';
    printAndLog('Updating Pi-hole\'s gravity using command \'' . $command . '\'...' . PHP_EOL);

    passthru($command, $return);

    if ($return !== 0) {
        printAndLog('Error occurred while updating gravity!' . PHP_EOL, 'ERROR');
        incrementStat('errors');
    } else {
        printAndLog('Done' . PHP_EOL, 'INFO', true);
    }

    print PHP_EOL;
} elseif ($config['UPDATE_GRAVITY'] === false) {
    $command = $config['PIHOLE_CMD'] . ' restartdns reload-lists';
    printAndLog('Reloading Pi-hole\'s lists using command \'' . $command . '\'...');

    system($command, $return);

    if ($return !== 0) {
        printAndLog('Error occurred while reloading lists!' . PHP_EOL, 'ERROR');
        incrementStat('errors');
    } else {
        printAndLog(' done' . PHP_EOL, 'INFO');
    }

    print PHP_EOL;
}

if ($config['DEBUG'] === true) {
    printAndLog('Memory reached peak usage of ' . formatBytes(memory_get_peak_usage()) . PHP_EOL, 'DEBUG');
}

if ($stat['invalid'] > 0) {
    printAndLog('Ignored ' . $stat['invalid'] . ' invalid ' . ($stat['invalid'] === 1 ? 'entry' : 'entries') . '.' . PHP_EOL, 'NOTICE');
}

if ($stat['conflict'] > 0) {
    printAndLog('Found ' . $stat['conflict'] . ' conflicting ' . ($stat['conflict'] === 1 ? 'entry' : 'entries') . ' across your lists.' . PHP_EOL, 'NOTICE');
}

$elapsedTime = round(microtime(true) - $startTime, 2) . ' seconds';

if ($stat['errors'] > 0) {
    printAndLog('Finished with ' . $stat['errors'] . ' error(s) in ' . $elapsedTime . '.' . PHP_EOL);
    exit(1);
}

if ($stat['warnings'] > 0) {
    printAndLog('Finished successfully with ' . $stat['warnings'] . ' warning(s) in ' . $elapsedTime . '.' . PHP_EOL);
    exit(0);
}

printAndLog('Finished successfully in ' . $elapsedTime . '.' . PHP_EOL);
