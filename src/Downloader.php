<?php
namespace pivanov;
/**
 * Class Downloader
 *
 *  загрузчик контента
 *
 * @author Petr Ivanov (C) petr.yrs@gmail.com
 */

use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;

class Downloader
{
    /**
     * @var bool Использовать прокси
     */
    private $useProxy      = false;
    /**
     * @var string Адрес прокси сервера
     */
    private $proxyHost     = '';
    /**
     * @var string Порт прокси сервера
     */
    private $proxyPort     = '';
    /**
     * @var string Имя пользователя прокси
     */
    private $proxyUser     = '';
    /**
     * @var string Пароль для подключения к прокси
     */
    private $proxyPass     = '';
    /**
     * @var bool Использовать Selenium вместо CURL
     */
    private $useSelenium   = false;
    /**
     * @var string Строка подключения к Selenium
     */
    private $seleniumHost  = 'http://localhost:4444/wd/hub';
    /**
     * @var bool Использовать файловый кеш
     */
    private $useCache      = false;
    /**
     * @var string Каталог для файлового кеша
     */
    private $cacheDir      = '';
    /**
     * @var int Код ответа
     */
    private $response_code = 0;
    /**
     * @var array Подробная информация об ответе
     */
    private $response_info = [];
    /**
     * @var string Имя файла к кеше
     */
    private $lastCacheFile = '';
    /**
     * @var array Полученные заголовки
     */
    public $reciveHeaders = [];
    /**
     * @var bool использовать cookie в рамках домена
     */
    public $useCookieByHost = true;
    /**
     * @var string[] Отправляемые заголовки
     */
    public         $sendHeaders = [
        'User-Agent' => 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:82.0) Gecko/20100101 Firefox/82.0',
        'Accept' => '*/*',
        'Accept-Language' => 'ru-RU,ru;q=0.8,en-US;q=0.5,en;q=0.3',
        'Accept-Encoding' => 'gzip, deflate',
        'Content-Type' => 'text/plain;charset=UTF-8',
    ];


    /**
     * Downloader constructor.
     *
     * @param array $params
     *                                  useProxy boolean
     *                                  proxy array
     *                                  host
     *                                  port
     *                                  user
     *                                  pass
     *                                  useCache boolen
     *                                  cacheDir string
     *                                  useSelenium boolean
     *                                  seleniumHost string
     */
    public function __construct($params = [])
    {
        if ( ! empty($params)) {
            if (isset($params['useProxy'])) $this->useProxy($params['useProxy']);
            if (isset($params['proxy'])) {
                $this->setProxy(
                    $params['proxy']['host'],
                    $params['proxy']['port'],
                    $params['proxy']['user'],
                    $params['proxy']['pass']
                );
            }

            if (isset($params['useCache'])) $this->useCache($params['useCache']);
            if (isset($params['cacheDir'])) $this->setCacheDir($params['cacheDir']);

            if (isset($params['useSelenium'])) $this->useSelenium($params['useSelenium']);
            if (isset($params['seleniumHost'])) $this->setSeleniumHost($params['seleniumHost']);
        }
    }


    /**
     * Включить/выключить использование прокси
     *
     * @param boolean $use
     *
     * @return $this
     */
    public function useProxy($use = true)
    {
        $this->useProxy = $use;

        return $this;
    }


    /**
     * Установить параметры прокси
     *
     * @param string $host
     * @param string $port
     * @param string $user
     * @param string $pass
     *
     * @return $this
     */
    public function setProxy($host, $port, $user = '', $pass = '')
    {
        $this->proxyHost = $host;
        $this->proxyPort = $port;
        $this->proxyUser = $user;
        $this->proxyPass = $pass;

        return $this;
    }


    public function useSelenium($use = true)
    {
        $this->useSelenium = $use;

        return $this;
    }


    /**
     * Установить параметры подключения к Selenium
     *
     * @param string $uri
     *
     * @return $this
     */
    public function setSeleniumHost($uri)
    {
        $this->seleniumHost = $uri;

        return $this;
    }


    /**
     * Включить/выключить использование файлового кеша
     *
     * @param bool $use
     *
     * @return $this
     */
    public function useCache($use = true)
    {
        $this->useCache = $use;

        return $this;
    }


    /**
     * Установить каталог для файлового кеша
     *
     * @param $dir
     *
     * @return $this
     */
    public function setCacheDir($dir)
    {
        $this->cacheDir = $dir;
        if ( ! file_exists($dir)) {
            mkdir($dir, 0777, true);
        }

        return $this;
    }


    /**
     * Очистить файловый кеш
     *
     * @return $this
     */
    public function clearCache()
    {
        if (file_exists($this->cacheDir)) {
            foreach (glob($this->cacheDir . '/*') as $file) {
                unlink($file);
            }
        }

        return $this;
    }


    /**
     * Поместить данные в кеш
     *
     * @param string $name
     * @param string $content
     *
     * @return $this
     */
    public function addToCache($name, $content)
    {
        $fileName = md5($name);
        $this->lastCacheFile = $fileName;
        $fullName = $this->cacheDir . '/' . $fileName;
        if (file_exists($fullName)) {
            unlink($fullName);
        }
        file_put_contents($fullName, $content);

        return $this;
    }


    /**
     * Получить данные из кеша
     *
     * @param $name
     *
     * @return false|string
     */
    public function getFromCache($name)
    {
        $fileName = md5($name);
        $fullName = $this->cacheDir . '/' . $fileName;
        if (file_exists($fullName)) {
            return file_get_contents($fullName);
        }

        return false;
    }


    /**
     * Получить данные через CURL
     *
     * @param string $url
     *
     * @return bool|string
     */
    private function getContentCurl($url)
    {

        $ch      = curl_init($url);

        $hostName = parse_url($url, PHP_URL_HOST);

//        curl_setopt($ch, CURLOPT_COOKIEFILE, __DIR__ . '/cookie.txt');
//        curl_setopt($ch, CURLOPT_COOKIEJAR, __DIR__ . '/cookie.txt');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headersAsArray());
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_ENCODING, "");
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_URL, $url);

        if ($this->useProxy) {
            curl_setopt($ch, CURLOPT_PROXY, $this->proxyHost);
            curl_setopt($ch, CURLOPT_PROXYPORT, $this->proxyPort);

            if ( ! empty($this->proxyUser)) {
                $proxyAuth = $this->proxyUser . ':' . $this->proxyPass;
                curl_setopt($ch, CURLOPT_PROXYTYPE, 'HTTP');
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxyAuth);
            }
        }

        $res = curl_exec($ch);

        $header_size         = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $this->response_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $this->response_info = curl_getinfo($ch);

        $this->parseHeaders(substr($res, 0, $header_size));
        $body = substr($res, $header_size);

        curl_close($ch);

        return $body;
    }


    /**
     * Преобразовать строку заголовков в массив
     *
     * @param string $s строка заголовков
     *
     * @return $this
     */
    private function parseHeaders($s)
    {
        $buf = explode("\r\n", $s);
        array_shift($buf);

        foreach ($buf as $item) {
            $r = explode(' ', $item);
            $v = array_pop($r);
            $k = array_pop($r);

            $this->reciveHeaders[$k] = $v;
        }

        return $this;
    }


    /**
     * Получить заголовки запроса
     *
     * @return array
     */
    public function getHeaders()
    {
        return $this->reciveHeaders;
    }


    /**
     * Получить заголовок по имени
     *
     * @param string $name имя
     *
     * @return false|mixed
     */
    public function getHeaderByName($name)
    {
        if (isset($this->reciveHeaders[$name])) {
            return $this->reciveHeaders[$name];
        } else {
            return false;
        }
    }


    /**
     * Получить код ответа
     *
     * @return int
     */
    public function getResponseCode()
    {
        return $this->response_code;
    }


    /**
     * Получить подробную информацию об ответе
     *
     * @return array
     */
    public function getResponseInfo()
    {
        return $this->response_info;
    }


    /**
     * Получить содержимое через Selenium
     *
     * @param string $url
     *
     * @return string
     */
    private function getContentSelenium($url)
    {
        $capabilites = DesiredCapabilities::chrome();

        if ($this->useProxy) {
            // Chrome
            $options = new \Facebook\WebDriver\Chrome\ChromeOptions();

            $manifest = [
                "version"                => "1.0.1",
                "manifest_version"       => 2,
                "name"                   => "Chrome Proxy",
                "permissions"            => [
                    "proxy",
                    "tabs",
                    "unlimitedStorage",
                    "storage",
                    "<all_urls>",
                    "webRequest",
                    "webRequestBlocking",
                    "incognito",
                ],
                "background"             => [
                    "scripts" => ["background.js"],
                ],
                "minimum_chrome_version" => "22.0.0",
            ];

            $background_js = <<<JS
var config = {
        mode: "fixed_servers",
        rules: {
          singleProxy: {
            scheme: "http",
            host: "$this->proxyHost",
            port: parseInt($this->proxyPort)
          },
          bypassList: ["localhost"]
        }
      };

chrome.proxy.settings.set({value: config, scope: "regular"}, function() {});

function callbackFn(details) {
    return {
        authCredentials: {
            username: "$this->proxyUser",
            password: "$this->proxyPass"
        }
    };
}

chrome.webRequest.onAuthRequired.addListener(
            callbackFn,
            {urls: ["<all_urls>"]},
            ['blocking']
);
JS;
            $zip           = new ZipArchive();
            $filename      = "./proxy_auth_plugin.zip";

            if ($zip->open($filename, ZipArchive::CREATE) !== true) {
                exit("Невозможно открыть <$filename>\n");
            }

            $zip->addFromString("manifest.json", json_encode($manifest));
            $zip->addFromString("background.js", $background_js);
            $zip->close();

            $options->addExtensions([$filename]);

            $capabilites->setCapability(\Facebook\WebDriver\Chrome\ChromeOptions::CAPABILITY, $options);
        }

        $driver = RemoteWebDriver::create($this->seleniumHost, $capabilites);

        $driver->get($url);

        $res = $driver->getPageSource();

        $driver->close();

        if ( ! empty($filename)) {
            unlink($filename);
        }

        return $res;
    }


    /**
     * Получить данные
     *
     * @param string $url
     *
     * @return string
     */
    public function getContent($url)
    {
        Log::info('Получаем контент из ' . $url);
        $refreshCache = false;
        // Получить из файлового кеша
        if ($this->useCache) {
            $html = $this->getFromCache($url);
            if ( ! empty($html)) {
                Log::info('Взяли из кеша');
            }
        }

        if (empty($html)) {
            if ($this->useProxy) {
                if ( ! empty(Yii::app()->params['proxy']['sleepTime'])) {
                    $sleepTime = Yii::app()->params['proxy']['sleepTime'];
                } else {
                    $sleepTime = 30;
                }
                Log::info('Перед использованием прокси спим ' . $sleepTime . ' сек');
                sleep($sleepTime);
            }

            // если в кеше нет данных или он не используется
            if ($this->useSelenium) {
                Log::info('Получаем через Selenium');
                $html = $this->getContentSelenium($url);
            } else {
                Log::info('Получаем через Curl');
                $html = $this->getContentCurl($url);
            }
            if ( ! empty($html) && in_array($this->response_code, [0, 200])) {
                $refreshCache = true;
            }
        }

        if ($this->useCache && $refreshCache) {
            Log::info('Кладем в кеш');
            $this->addToCache($url, $html);
        }

        return $html;
    }


    /**
     * Удалить из кеша
     * @param string $fileName
     *
     * @return $this
     */
    public function removeFromCache($fileName = ''){
        if (empty($fileName)) {
            $fileName = $this->lastCacheFile;
            $fullName = $this->cacheDir . '/' . $fileName;
            if (file_exists($fullName)) {
                unlink($fullName);
            }
        }
        return $this;
    }


    /**
     * Изменить параметр отправляемого заголовка
     * @param $key название ключа
     * @param $value значение ключа
     *
     * @return $this
     */
    public function setHeader($key, $value){
            $this->sendHeaders[$key] = $value;
            return $this;
    }


    /**
     * Преобразовать отправляемые заголовки в массив строк (без ключей)
     * @return string[]
     */
    public function headersAsArray(){
        $res = [];
        foreach ($this->sendHeaders as $k => $v) {
            $res[] = $k.': '.$v;
        }
        return $res;
    }
}