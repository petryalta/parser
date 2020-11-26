<?php

namespace pivanov;

use simplehtmldom\HtmlDocument;
use phpQuery;

/**
 * Class Parser
 * Парсинг страницы
 *
 * @author Petr Ivanov (petr.yrs@gmail.com)
 */
class Parser
{
    /**
     * @var string Адрес страницы
     */
    public $url;
    /**
     * @var array Шаблон(ы). Ключ - тип шаблона (jq, regex, xpath, funcm tabl)
     */
    public $templates;
    /**
     * @var string HTML-содержимое страницы или блока
     */
    public $content;
    /**
     * @var string Результат парсинга
     */
    public $val;
    /**
     * @var bool Использовать Selenium для получения HTML
     */
    private $useSelenium = false;
    /**
     * @var string Адрес подключения Selenium
     */
    private $seleniumHost = 'http://localhost:4444/wd/hub';
    /**
     * @var bool Использовать прокси
     */
    private $useProxy = false;
    /**
     * @var array
     *           host       IP прокси
     *           port       порт
     *           username   пользователь
     *           password   пароль
     */
    private $proxyParams = [];
    /**
     * @var bool Использовать кеш
     */
    private $useCache = false;
    /**
     * @var Каталог для файлового кеша
     */
    private $cacheDir;
    /**
     * @var bool Возвращать из шаблона HTML код, а не текст
     */
    private $returnRaw = false;
    /**
     * @var string Маркер капчи
     */
    private $captchaMarker = '';
    /**
     * @var Psr\Log
     */
    private $logger;
    /**
     * @var Downloader
     */
    private $downloader;


    public function __construct()
    {
        $this->downloader = new Downloader();
    }


    /**
     * Добавить шаблон
     *
     * @param string $type тип шаблона
     * @param string $tpl  значение шаблона
     *
     * @return false
     */
    public function addTemplate($type, $tpl)
    {
        if ( ! in_array($type, self::availTypes())) {
            return false;
        }
        $this->templates[] = [$type => $tpl];

        return $this;
    }


    /**
     * Доступные типы шаблона
     *
     * @return string[]
     */
    public static function availTypes()
    {
        return [
            'jq'    => 'jQuery',
            'xpath' => 'XPath',
            'regex' => 'Regex',
            'func'  => 'Func',
            'tabl'  => 'Table',
            'save'  => 'Save',
        ];
    }


    /**
     * Парсинг документа
     *
     * @param string $content HTML-документ
     * @param string $type    тип шаблона
     * @param string $tpl     шаблон
     *
     * @return string
     */
    public function parse($content, $type, $tpl)
    {
        $this->content = $content;
        $this->val     = '';

        switch ($type) {
            case 'xpath':
                $this->val = $this->parseXpath($tpl);
                break;
            case 'regex':
                $this->val = $this->parseRegex($tpl);
                break;
            case 'func':
                $this->val = $this->parseFunc($tpl);
                break;
            case 'jq':
                $this->val = $this->parseJquery($tpl);
                break;
            case 'tabl':
                $this->val = $this->parseTable($tpl);
                break;
            case 'save':
                $this->val = $this->parseSave($tpl);
                break;
        }

        return $this->val;
    }


    /**
     * Поиск по XPath
     *
     * @param string $query
     *
     * @return string
     */
    private function parseXpath($query)
    {
        if (substr($query, 0, 3) == 'arr') {
            $returnArray = true;
            $query       = mb_substr($query, 4, strlen($query) - 4);
        } else $returnArray = false;

        $content = mb_convert_encoding($this->content, 'HTML-ENTITIES', 'utf-8');
        if (empty($content)) {
            return '';
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        if ( ! $dom->loadHTML($content)) {
            foreach (libxml_get_errors() as $error) {
                $this->logError('error', $error);
            }
            libxml_clear_errors();
        }

        $xpath = new \DOMXPath($dom);
        $res   = $xpath->query($query);
        if ($res === false) return '';
        $result      = '';
        $resArr      = [];
        $returnArray = $res->length > 1;
        foreach ($res as $item) {
            if ($this->returnRaw) {
                $result .= $dom->saveHTML($item);
            } else {
                if ($returnArray) {
                    $resArr[] = $item->textContent;
                } else {
                    $result .= $item->textContent;
                }
            }
        }
        if ($this->returnRaw) return $result;

        return ($returnArray) ? $resArr : $result;
    }


    /**
     * Поиск по регулярном выражению
     *
     * @param string $reg
     *
     * @return array|string
     */
    private function parseRegex($reg)
    {
        if ( ! is_string($this->content)) {
            $this->logError('error', 'Контент не является строкой');

            return [];
        }
        try {
            if (preg_match_all($reg, $this->content, $mathes) > 0) return $mathes;
            else return '';
        } catch (\Exception $e) {
            $this->logError('error', $e->getMessage());
        }
    }


    /**
     * Выполнить PHP-функцию
     *
     * @param string $val
     *
     * @return mixed
     */
    private function parseFunc($val)
    {
        if (empty($val) || empty($this->content)) return '';

        return call_user_func(function ($data) use ($val) {
            return eval("return $val ;");
        }, $this->content);
    }


    /**
     * Парсинг по jQuery Selector
     *
     * @param string $selector
     *
     * @return string
     */
    private function parseJquery($selector)
    {
        $raw = $this->returnRaw;

        if (substr($selector, 0, 3) == 'raw') {
            $raw      = true;
            $selector = mb_substr($selector, 4, strlen($selector) - 4);
        }

        if ( ! is_string($this->content)) {
            $this->logError('error', 'Контент не является строкой');

            return '';
        }

        if (preg_match('|<body.*</body>|isU', $this->content, $preg)) {
            $dom = phpQuery::newDocumentHTML();
            $dom->html($preg[0]);
        } else {
            $dom = phpQuery::newDocumentHTML($this->content);
        }

        $res = $dom->find($selector);

        if ($res->count() < 1) return '';

        if ($raw) return $res->htmlOuter();
        else return $res->text();
    }


    /**
     * Парсинг таблицы характеристик
     *
     * @param $selector
     *
     * @return array
     */
    private function parseTable($selector)
    {
        if ( ! is_string($this->content)) {
            $this->logError('error', 'Контент не является строкой');

            return [];
        }
        $type = 'tr'; // тип таблицы по умолчанию

        $params = explode('|', $selector);
        if (count($params) == 4) {
            $type         = array_shift($params);
            $rowSelector  = array_shift($params);
            $attrSelector = array_shift($params);
            $valSelector  = array_shift($params);
        }

        switch ($type) {
            case 'tr':
                $rowSelector  = $rowSelector ?? 'tr';
                $attrSelector = $attrSelector ?? 'td';
                $valSelector  = $valSelector ?? 'td:nth-child(2)';
                break;
            case 'dl':
                $rowSelector  = 'dl';
                $attrSelector = 'dt';
                $valSelector  = 'dd';
                break;
            case 'div':
                $rowSelector  = '';
                $attrSelector = '';
                $valSelector  = '';
                break;
            case 'li':
                $rowSelector  = '';
                $attrSelector = '';
                $valSelector  = '';
                break;
            default:
                break;
        }

        $dom = new HtmlDocument(null);
        $dom->load($this->content, false, false);

        $rows = $dom->find($rowSelector);
        $res  = [];
        foreach ($rows as $row) {
            $nodes = $row->find($attrSelector);

            if ($type == 'tr') {
                $attrObj = array_shift($nodes);
                $valObj  = array_shift($nodes);

                if ( ! empty($attrObj) && ! empty($valObj)) {
                    $attr = $attrObj->innertext();
                    $val  = $valObj->innertext();

                    if ( ! empty($attr) && ! empty($val)) {
                        $res[$attr] = $val;
                    }
                }
            }
        }

        return $res;
    }


    /**
     * Получить кодировку, указанную в странице
     *
     * @param $content string
     *
     * @return string
     */
    public function detectCharset($content)
    {
        $p1 = strpos($content, 'http-equiv="Content-Type"');
        if ($p1 !== false) {
            $p2      = strpos($content, '>', $p1);
            $l       = $p2 - $p1;
            $s       = substr($content, $p1, $l);
            $b       = explode('=', $s);
            $charset = $b[3];
            $charset = str_replace('"', '', $charset);
            $charset = str_replace('/', '', $charset);

            return trim($charset);
        } else {
            $this->logError('warning', 'DetectCharset: meta http-equiv не найден');

            return false;
        }
    }


    /**
     * Обработать все шаблоны
     *
     * @return mixed
     */
    public function parseAll()
    {
        $res = $this->content;
        if ( ! empty($res)) {
            if (count($this->templates) == 0) {
                return false;
            }
            foreach ($this->templates as $template) {
                foreach ($template as $type => $tpl) {
                    if (is_string($tpl)) {
                        $res = $this->parse($res, $type, $tpl);
                    } else {
                        throw new TemplateException(print_r($tpl, true));
                    }
                }
            }
        }

        return $res;
    }


    /**
     * Загрузить контент
     *
     * @param string $content
     *
     * @return $this
     */
    public function setContent($content = '')
    {
        if ( ! empty($content)) {
            $this->content = $content;

            return $this;
        }

        $this->downloader = new Downloader([
            'useSelenium'  => $this->useSelenium,
            'seleniumHost' => $this->seleniumHost,
            'useCache'     => $this->useCache,
            'cacheDir'     => $this->cacheDir,
            'useProxy'     => $this->useProxy,
            'proxy'        => ($this->useProxy) ? [
                'host' => $this->proxyParams['host'],
                'port' => $this->proxyParams['port'],
                'user' => $this->proxyParams['username'],
                'pass' => $this->proxyParams['password'],
            ] : [
                'host' => '',
                'port' => '',
                'user' => '',
                'pass' => '',
            ],
        ]);

        $this->content = $this->downloader->getContent($this->url);
        $code          = $this->downloader->getResponseCode();
        if ( ! in_array($code, [0, 200])) {
            throw new ResponseException("Response code $code");
        }

        if ($this->checkCaptcha()) {
            throw new CaptchaException('Captcha detected');
        }

        try {
            $inEnc = self::detectCharset($this->content);
            if ($inEnc) {
                $this->content = mb_convert_encoding($this->content, 'UTF-8', $inEnc);
            }
        } catch (Exception $e) {
            $this->logError('error', $e->getMessage());
        }

        return $this;
    }


    /**
     * Установить URL
     *
     * @param string $url
     *
     * @return $this
     */
    public function setUrl($url)
    {
        $this->url = $url;

        return $this;
    }


    /**
     * Установить шаблоны
     *
     * @param array $tpls
     *
     * @return $this
     */
    public function setTemplates(array $tpls)
    {
        if (empty($tpls)) {
            throw new TemplateException('Не указаны шаблоны');
        }

        $this->templates = $tpls;

        return $this;
    }


    /**
     * Сохранение в модель
     *
     * @param string $inParam
     *                       первая строка - название модели
     *                       вторая строка - название поля
     *                       Возможно указание через точку с запятой
     *
     * @return false|mixed
     */
    private function parseSave($inParam)
    {
        $inParam = str_replace("\r", "", $inParam);
        $params  = preg_split("/[\n;]/", $inParam);
        if (count($params) != 2) {
            throw new Exception('Не верные параметры сохранения');
        }
        $modelName  = $params[0];
        $fieldValue = $params[1];

        $model                = new $modelName();
        $model->{$fieldValue} = $this->content;

        return $model;
    }


    /**
     * Получить контент
     *
     * @return string
     */
    public function getContent()
    {
        return $this->content;
    }


    /**
     * Включить/выключить использование Selenium
     *
     * @param bool $use
     *
     * @return $this
     */
    public function useSelenium($use = true)
    {
        $this->useSelenium = $use;
        $this->downloader->useSelenium($use);

        return $this;
    }


    /**
     * Включть использование Selenium и указать адрес подключения
     *
     * @param string $host Адрес подключения
     *
     * @return $this
     */
    public function setSeleniumHost($host)
    {
        $this->useSelenium  = true;
        $this->seleniumHost = $host;

        $this->downloader->useSelenium(true);
        $this->downloader->setSeleniumHost($host);

        return $this;
    }


    /**
     * Установить параметры прокси
     *
     * @param $params
     *               host
     *               port
     *               username
     *               pass
     *
     * @return $this
     */
    public function setProxy($params)
    {
        $this->proxyParams = $params;
        $this->useProxy(true);

        $this->downloader->useProxy(true);
        $this->downloader->setProxy(
            $params['proxy']['host'],
            $params['proxy']['port'],
            $params['proxy']['user'],
            $params['proxy']['pass']
        );

        return $this;
    }


    /**
     * Включить/выключить использование прокси
     *
     * @param false $use
     *
     * @return $this
     */
    public function useProxy($use = false)
    {
        $this->useProxy = $use;
        $this->downloader->useProxy($use);

        return $this;
    }


    public function useCache($use = true)
    {
        $this->useCache = $use;
        $this->downloader->useCache($use);

        return $this;
    }


    /**
     * Добавить в лог ошибки
     *
     * @return $this
     */
    public function logError($level, $msg)
    {
        if ( ! empty($this->logger)) {
            $this->logger->log($level, $msg);
        }

        return $this;
    }


    /**
     * Переключение режимов возврата
     *
     * @param bool $set
     */
    public function returnRaw($set = true)
    {
        $this->returnRaw = $set;

        return $this;
    }


    /**
     * Установить маркер капчи
     *
     * @param $marker
     *
     * @return $this
     */
    public function setCaptchaMarker($marker)
    {
        $this->captchaMarker = $marker;

        return $this;
    }


    /**
     * Проверка на наличии маркера капчи
     *
     * @return bool
     */
    private function checkCaptcha()
    {
        if ( ! empty($this->captchaMarker) && ! empty($this->content)) {
            return (strpos($this->content, $this->captchaMarker) !== false) ? true : false;
        } else {
            return false;
        }
    }


    /**
     * Установить логер
     *
     * @param $logger
     */
    public function setLogger($logger)
    {
        $this->logger = $logger;
    }


    /**
     * Получить загрузчк
     *
     * @return Downloader
     */
    public function getDownloader()
    {
        return $this->downloader;
    }


    /**
     * Установить каталог для файлового кеша
     *
     * @param string $dir
     *
     * @return $this
     */
    public function setCacheDir($dir)
    {
        $this->cacheDir = $dir;
        $this->downloader->setCacheDir($dir);
        $this->useCache(true);
        $this->downloader->useCache(true);

        return $this;
    }


    /**
     * Удалить последний кешированный контент
     *
     * @return $this
     */
    public function removeLastCacheFile()
    {
        $this->downloader->removeFromCache();

        return $this;
    }


    /**
     * Установить User-Agent
     *
     * @param string $value
     *
     * @return $this
     */
    public function setUserAgent($value)
    {
        $this->downloader->setHeader('User-Agent', $value);

        return $this;
    }
}