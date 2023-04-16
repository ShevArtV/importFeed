<?php
ini_set('memory_limit', '128M');
define('MODX_API_MODE', true);
require_once dirname(__FILE__, 3) . '/index.php';

class ImportFeed
{
    public ModX $modx;
    public string $basepath;
    public string $logpath;
    public string $feedPath;
    public string $imagePath;
    public array $config;
    public array $options;

    public function __construct($modx)
    {
        $this->modx = $modx;
        $this->basepath = $this->modx->getOption('base_path');
        $this->logpath = $this->basepath . 'import_log.txt';
        $this->config = include('importconfig.inc.php');
        $this->feedPath = $this->basepath . $this->config['feedPath'];
        $this->imagePath = $this->basepath . $this->config['imagePath'];
        $this->options = [];

        $this->start();
    }

    public function start()
    {
        if (file_exists($this->logpath)) {
            unlink($this->logpath);
        }

        if ($this->config->feedUrl) {
            if ($this->downloadFeed()) {
                $this->log('[ImportFeed::start] Загружен файл фида.');
            } else {
                $this->log('[ImportFeed::start] Не удалось скачать файл фида.');
                return false;
            }
        } else {
            $this->log("[ImportFeed::start] {$this->feedPath}");
            if (file_exists($this->feedPath)) {
                $this->log('[ImportFeed::start] Будет произведён импорт из имеющегося файла фида.');
            } else {
                $this->log('[ImportFeed::start] Файл фида отсутствует. Загрузите его, чтобы выполнить импорт.');
                return false;
            }
        }

        $this->log('[ImportFeed::start] Начат импорт.');
        if ($this->config['importCategories']) {
            $this->importCategories();
            //$this->getCategoriesData();
        }
        if ($this->config['importProducts']) {
            $this->importProducts();
            //$this->getProductsData();
        }
    }

    private function downloadFeed()
    {
        return $this->download($this->config['feelUrl'], $this->feedPath);
    }

    private function download($url, $path)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HEADER, false);
        $html = curl_exec($ch);
        curl_close($ch);

        return file_put_contents($path, $html) ? $path : '';
    }

    private function importCategories()
    {
        $this->log('[ImportFeed::getCategoriesData] Начато получение данных категорий для импорта.');
        if ($reader = $this->getXmlReader($this->feedPath, 'categories')) {
            if ($xml = $this->readXml($reader)) {
                $this->log('[ImportFeed::getCategoriesData] Начат импорт категорий.');
                $c = 0;
                foreach ($xml->category as $item) {
                    $c++;
                    $parentFeedId = $item->attributes()->parentId ? $item->attributes()->parentId->__toString() : '';
                    $feedId = $item->attributes()->id ? $item->attributes()->id->__toString() : '';
                    $categoryData = array_merge($this->config['categoryDefaultFields'], [
                        'pagetitle' => $item->__toString(),
                        'feed_id' => $feedId,
                    ]);
                    if ($feedId && $parentFeedId) {
                        if (!$categoryData['parent'] = $this->getParentId($parentFeedId)) {
                            return false;
                        }
                    }
                    $this->manageResource($categoryData);
                }
            } else {
                $this->log('[ImportFeed::getCategoriesData] Не удалось прочитать данные категорий из фида.', [], 1);
            }
        } else {
            $this->log('[ImportFeed::getCategoriesData] Не удалось получить данные категорий для чтения.', [], 1);
        }
    }

    private function importProducts()
    {
        $this->log("[ImportFeed::importProducts] $this->feedPath");
        if ($reader = $this->getXmlReader($this->feedPath, 'offers')) {
            if ($xml = $this->readXml($reader)) {
                $this->log('[ImportFeed::importProducts] Начат импорт товаров.');
                $c = 0;
                foreach ($xml->offer as $item) {
                    $c++;
                    $id = $item->attributes()->id ? $item->attributes()->id->__toString() : '';

                    foreach ($item->param as $param) {
                        $key = $param->attributes()->name->__toString();
                        $value = $param->__toString();
                        $this->options[$key] = $value;
                    }

                    $productData = $this->getData($item, $this->config['productFields']);
                    $vendorData = $this->getData($item, $this->config['vendorFields']);
                    $productData = array_merge($this->config['productDefaultFields'], $productData, [
                        'feed_id' => $id,
                        'parent' => $this->getParentId($item->categoryId->__toString())
                    ]);
                    if ($this->config['removeEmpty']) {
                        $productData = array_filter($productData, function($k, $v){
                            return (in_array($k, ['published', 'show_in_tree', 'hidemenu']) || $v);
                        },ARRAY_FILTER_USE_BOTH);
                        $vendorData = array_filter($vendorData, fn($el) => $el);
                    }
                    if ($vendorData['name']) {
                        $productData['vendor'] = $this->createVendor($vendorData);
                    }

                    if ($resource = $this->modx->getObject('modResource', $this->manageResource($productData))) {
                        if($this->config['setOptions']) {
                            $this->setOptions($resource);
                        }
                        if($this->config['setGallery']){
                            $this->setGallery((array)$item->picture, $resource);
                        }
                    }

                    $this->options = [];
                }
                $this->log('[ImportFeed::importProducts] Импорт окончен. Импортировано товаров: ' . $c);
                unset($xml);
            } else {
                $this->log('[ImportFeed::importProducts] Не удалось прочитать данные товаров из фида.', [], 1);
            }
        } else {
            $this->log('[ImportFeed::importProducts] Не удалось получить данные товаров для чтения.', [], 1);
        }
    }

    private function createVendor($vendorData)
    {
        if ($vendorData['logo']) {
            $logoPath = $this->imagePath . basename($vendorData['logo']);
            if (!file_exists($logoPath)) {
                $this->download($vendorData['logo'], $logoPath);
            }
            $vendorData['logo'] = $this->config['imagePath'] . basename($vendorData['logo']);
        }
        if (!$vendor = $this->modx->getObject('msVendor', ['name' => $vendorData['name']])) {
            $vendor = $this->modx->newObject('msVendor');
        }
        $vendor->fromArray($vendorData, '', 1);
        $vendor->save();
        return $vendor->get('id');
    }

    private function getData($item, $fields)
    {
        $data = [];
        $fieldPrefix = 'name_';
        foreach ($fields as $k => $v) {
            if (strpos($v, $fieldPrefix) === 0) {
                $key = str_replace($fieldPrefix, '', $v);
                $data[$k] = $this->options[$key];
                unset($this->options[$key]);
            } else {
                $data[$k] = $item->$v->__toString();
            }
        }
        return $data;
    }

    private function getParentId($feedId)
    {
        if ($resource = $this->modx->getObject('modResource', ['feed_id' => $feedId])) {
            return $resource->get('id');
        }

        return $this->manageResource(['feed_id' => $feedId]);
    }

    private function manageResource($data)
    {
        if (empty($data)) {
            $this->log('[ImportFeed::createResource] Не переданы данные ресурса.', [], true);
        }
        if (!$data['pagetitle']) {
            $data['pagetitle'] = 'Ресурс ' . time();
        } else {
            if ($this->config['createUniquePagetitle']) {
                $data['pagetitle'] .= ' ' . time();
            }
        }
        foreach ($this->config['truncated'] as $field => $length){
            if($length){
                $data[$field] = $this->truncate($data[$field], $length);
            }
        }

        if (!$resource = $this->modx->getObject($data['class_key'], ['pagetitle' => $data['pagetitle']])) {
            $resource = $this->modx->newObject($data['class_key']);
        }

        if ($this->config['saveAlias'] && $data['url']) {
            $url = explode('/', $data['url']);
            $data['alias'] = $url[count($url) - 1];
        } else {
            $data['alias'] = $resource->cleanAlias($data['pagetitle']);
        }

        $this->log('[ImportFeed::createResource] Будет обработан ресурс со следующими данными.', $data);
        $resource->fromArray($data, '', 1);
        $resource->save();
        return $resource->get('id');

    }

    private function setOptions($resource)
    {
        if (!empty($this->options)) {

            foreach ($this->options as $name => $value) {
                $option = $this->manageOption($name);
                $this->manageCategoryOption($option, $resource);
                $this->manageProductOption($option, $resource, $value);
            }
        }
    }

    private function manageProductOption($option, $res, $val)
    {
        $this->log("[ImportFeed::manageCategoryOption] Опция для товара.", ['key' => $option->key, 'value' => $val, 'rid' => $res->id]);
        if ($this->modx->getObject('msProductOption', array('product_id' => $res->id, 'key' => $option->key))) {
            $q = $this->modx->newQuery('msProductOption');
            $q->command('UPDATE');
            $q->where(array('key' => $option->key,'product_id' => $res->id));
            $q->set(array('value' => $val));
            $q->prepare();
            $q->stmt->execute();
            $this->log("[ImportFeed::manageCategoryOption] Опция для товара обновлена.");
        } else {
            $table = $this->modx->getTableName('msProductOption');
            if (!is_int($val)) {
                $val = '"' . $val . '"';
            }
            $sql = "INSERT INTO {$table} (`product_id`,`key`,`value`) VALUES ({$res->id}, \"{$option->key}\", {$val});";
            $stmt = $this->modx->prepare($sql);
            $stmt->execute();
            $this->log("[ImportFeed::manageCategoryOption] Опция для товара создана.");
        }
    }

    private function manageCategoryOption($option, $res)
    {
        if (!$this->modx->getObject('msCategoryOption', array('option_id' => $option->id, 'category_id' => $res->parent))) {
            $table = $this->modx->getTableName('msCategoryOption');
            $sql = "INSERT INTO {$table} (`option_id`,`category_id`,`active`) VALUES ({$option->id}, {$res->parent}, 1);";
            $stmt = $this->modx->prepare($sql);
            $stmt->execute();
            $this->log("[ImportFeed::manageCategoryOption] Опция для категории создана.");
        } else {
            $q = $this->modx->newQuery('msCategoryOption');
            $q->command('UPDATE');
            $q->where(array('option_id' => $option->id, 'category_id' => $res->parent));
            $q->set(array('active' => 1));
            $q->prepare();
            $q->stmt->execute();
            $this->log("[ImportFeed::manageCategoryOption] Опция для категории обновлена.");
        }
    }

    private function manageOption($name)
    {
        $key = $this->translit($name);
        if (!$option = $this->modx->getObject('msOption', array('key' => $key))) {
            $option = $this->modx->newObject('msOption');
            $this->log("[ImportFeed::manageOption] Опция {$name} создана.");
            $option->fromArray(array('key' => $key, 'caption' => $name, 'type' => 'textfield'));
            $option->save();
        } else {
            $this->log("[ImportFeed::manageOption] Опция {$name} уже существует.");
        }
        return $option;
    }

    private function setGallery($photos, $resource)
    {
        if (empty($photos)) return false;
        $this->log("[ImportFeed::setGallery] Устанавливаем галерею", $photos);
        if ($this->config['removeOldFiles']) {
            if ($files = $resource->getMany('Files')) {
                foreach ($files as $f) {
                    $f->remove();
                }
                $this->log("[ImportFeed::setGallery] Старые файлы галереи были удалены");
            }
        }
        foreach ($photos as $url) {
            $this->log("[ImportFeed::setGallery] Обрабатывается фото {$url}");
            $path = $this->imagePath . basename($url);
            if (!file_exists($path)) {
                $path = $this->download($url, $path);
            }
            $data = [
                'id' => $resource->get('id'),
                'file' => $path,
                'description' => $resource->get('pagetitle'),
                'source' => $resource->get('source'),
            ];
            $response = $this->modx->runProcessor('gallery/upload', $data, [
                'processors_path' => $this->modx->getOption('core_path') . 'components/minishop2/processors/mgr/',
            ]);
            if ($response->isError()) {
                $this->log("[ImportFeed::setGallery] Не удалось загрузить фото в галерею", $response->getAllErrors());
            } else {
                $this->log("[ImportFeed::setGallery] Фото {$url} успешно загружено в галерею");
                unlink($path);
            }
        }
    }

    private function getXmlReader($filename, $search)
    {
        //read xml file
        if (!$filename) {
            $this->log("[ImportFeed::getXmlReader] Не передано имя файла фида.", [], 1);
        }
        $reader = new XMLReader;
        $success = $reader->open($filename);
        if (!$success) {
            $this->log("[ImportFeed::getXmlReader] Невозможно считать файл $filename. Возможно он содержит ошибки XML.", [], 1);
        }

        while ($reader->read()) {
            if ($reader->name === $search) {
                return $reader;
            }
        }
        $this->log("[ImportFeed::getXmlReader] Элемент $search не найден в файле $filename.", [], 1);
    }

    private function readXml($reader)
    {
        $outerXml = $reader->readOuterXML();
        return $outerXml ? new SimpleXMLElement($outerXml) : null;
    }

    private function translit($value)
    {
        $converter = array(
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
            'е' => 'e', 'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
            'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
            'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
            'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch',
            'ш' => 'sh', 'щ' => 'sch', 'ь' => '', 'ы' => 'y', 'ъ' => '',
            'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        );

        $value = mb_strtolower($value);
        $value = strtr($value, $converter);
        $value = mb_ereg_replace('[^-0-9a-z]', '-', $value);
        $value = mb_ereg_replace('[-]+', '-', $value);
        $value = trim($value, '-');

        return $value;
    }

    private function truncate($str, $length)
    {
        $arr = explode(' ', $str);
        $c = 0;
        $newArr = [];
        foreach ($arr as $r) {
            $c += mb_strlen($r);
            $newArr[] = $r;
            if ($c > $length) {
                break;
            }
        }
        return implode(' ', $newArr);
    }

    private function log($msg, $data = [], $isError = false)
    {
        if (!empty($data)) {
            $text = date('d.m.Y H:i:s') . ' ' . $msg . print_r($data, 1) . PHP_EOL;
        } else {
            $text = date('d.m.Y H:i:s') . ' ' . $msg . PHP_EOL;
        }
        file_put_contents($this->logpath, $text, FILE_APPEND);
        if ($isError) die();
    }
}

new ImportFeed($modx);