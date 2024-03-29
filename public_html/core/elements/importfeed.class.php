<?php
define('MODX_API_MODE', true);
require_once dirname(__FILE__, 3) . '/index.php';

class ImportFeed
{
    public ModX $modx;
    public string $basepath;
    public string $logpath;
    public string $feedPath;
    public string $imagePath;
    public string $finishFilePath;
    public string $readingDataPath;
    public array $config;
    public array $options;

    public function __construct($modx, $configPath = 'importconfig.inc.php')
    {
        $this->modx = $modx;
        $this->basepath = $this->modx->getOption('base_path');
        $this->logpath = $this->basepath . basename($configPath) . 'import_log.txt';
        $this->config = include($configPath);
        $this->feedPath = $this->basepath . $this->config['feedPath'];
        $this->imagePath = $this->basepath . $this->config['imagePath'];
        $this->options = [];
        $this->finishFilePath = $this->basepath . basename($configPath) . '_importfinished.txt';
        $this->readingDataPath = $this->basepath . basename($configPath) . '_readingdata.json';

        $this->start();
    }

    public function start()
    {
        if (file_exists($this->logpath) && file_exists($this->finishFilePath)) {
            unlink($this->logpath);
        }

        if (file_exists($this->finishFilePath)) {
            $this->log("[ImportFeed::start] Вы пытаетесь запустить повторный импорт. Если это действительно необходимо удалите файл {$this->finishFilePath}", [], 1);
        }


        if ($this->config['feedUrl']) {
            if ($this->downloadFeed()) {
                $this->log('[ImportFeed::start] Загружен файл фида.');
            } else {
                $this->log('[ImportFeed::start] Не удалось скачать файл фида.', [], 1);
            }
        } else {
            $this->log("[ImportFeed::start] {$this->feedPath}");
            if (file_exists($this->feedPath)) {
                $this->log('[ImportFeed::start] Будет произведён импорт из имеющегося файла фида.');
            } else {
                $this->log('[ImportFeed::start] Файл фида отсутствует. Загрузите его, чтобы выполнить импорт.', [], 1);
            }
        }

        if ($this->config['importCategories']) {
            $this->log('[ImportFeed::start] Начат импорт категорий.');
            $this->startReading($this->feedPath, 'category', 'importCategories');
            $this->log('[ImportFeed::start] Импорт категорий завершён.');
        }
        if ($this->config['importProducts']) {
            $this->log('[ImportFeed::start] Начат импорт товаров.');
            $this->startReading($this->feedPath, 'offer', 'importProducts');
            $this->log('[ImportFeed::start] Импорт товаров завершён.');
        }
        file_put_contents($this->finishFilePath, 'Все импорты завершены.');
        $this->modx->reloadContext('web');
        if (file_exists($this->readingDataPath)) {
            unlink($this->readingDataPath);
        }
        if ($this->config['productDefaultFields']['supplier']) {
            $this->setUnavailable();
        }

    }

    private function setImportStatus()
    {
        $c = $this->modx->newQuery('msProduct');
        $c->leftJoin('msProductData', 'Data');
        $c->where(['Data.favorite' => $this->config['productDefaultFields']['supplier']]);
        $c->prepare();
        $products = $this->modx->getIterator('msProduct', $c);
        foreach ($products as $product) {
            $product->set('status_import', 0);
            $product->save();
        }
    }

    private function setUnavailable()
    {
        $c = $this->modx->newQuery('msProduct');
        $c->leftJoin('msProductData', 'Data');
        $c->where(['Data.supplier' => $this->config['productDefaultFields']['supplier'], 'status_import' => 0]);
        $c->prepare();
        $products = $this->modx->getIterator('msProduct', $c);
        foreach ($products as $product) {
            $product->set('status_import', 1);
            $product->save();
        }
    }

    private function downloadFeed()
    {
        return $this->download($this->config['feedUrl'], $this->feedPath);
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

    private function importCategories($item)
    {
        $parentFeedId = $item->attributes()->parentId ? $item->attributes()->parentId->__toString() : '';
        $feedId = $item->attributes()->id ? $item->attributes()->id->__toString() : '';
        $categoryData = array_merge($this->config['categoryDefaultFields'], [
            'pagetitle' => $item->__toString(),
            'feed_id' => $feedId,
        ]);
        if ($feedId && $parentFeedId) {
            if (!$categoryData['parent'] = $this->getParentId($parentFeedId)) {
                $this->log("[ImportFeed::importCategories] Не удалось получить родительский ресурс для категории feed_id = $feedId");
                return false;
            }
        }
        unset($parentFeedId, $feedId, $item);
        $this->manageResource($categoryData);
    }

    private function importProducts($item)
    {
        $id = $item->attributes()->id ? $item->attributes()->id->__toString() : '';
        $this->config['productDefaultFields']['status_import'] = 1;
        foreach ($item->param as $param) {
            $key = trim($param->attributes()->name->__toString());
            if (in_array($key, $this->config['ignore_params'])) continue;
            $value = trim($param->__toString());
            if ($this->config['params_relations'][$key]) {
                $optionKey = $this->config['params_relations'][$key];
                $this->options[$optionKey][] = $value;
            } else {
                $this->options[$key][] = $value;
            }
            unset($param);
        }
        $productData = $this->getData($item, $this->config['productFields']);
        $vendorData = $this->getData($item, $this->config['vendorFields']);
        $productData = array_merge($this->config['productDefaultFields'], $productData, [
            'feed_id' => $id,
            'parent' => $this->getParentId($item->categoryId->__toString())
        ]);
        if ($this->config['removeEmpty']) {
            $productData = array_filter($productData, function ($k, $v) {
                return (in_array($k, ['published', 'show_in_tree', 'hidemenu']) || $v);
            }, ARRAY_FILTER_USE_BOTH);
            $vendorData = array_filter($vendorData, fn($el) => $el);
        }
        if ($vendorData['name']) {
            $productData['vendor'] = $this->createVendor($vendorData);
            unset($vendorData);
        }
        if ($resource = $this->manageResource($productData)) {
            if ($this->config['setOptions']) {
                $this->setOptions($resource);
            }
            if ($this->config['setGallery'] && !empty((array)$item->picture)) {
                $this->setGallery((array)$item->picture, $resource);
            }
            unset($resource);
        }
        unset($item);
        $this->options = [];
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
        $id = $vendor->get('id');
        unset($vendor);
        return $id;
    }

    private function getData($item, $fields)
    {
        $data = [];
        $fieldPrefix = 'name_';
        $optionPrefix = 'option_';
        foreach ($fields as $k => $v) {
            if (strpos($v, $fieldPrefix) === 0) {
                $key = str_replace($fieldPrefix, '', $v);
                $data[$k] = is_array($this->options[$key]) ? implode(', ', $this->options[$key]) : $this->options[$key];
                unset($this->options[$key]);
            } else if (strpos($v, $optionPrefix) === 0) {
                $key = str_replace($optionPrefix, '', $v);
                $this->options[$key][] = html_entity_decode(trim($item->$v->__toString()));
            } else {
                $data[$k] = html_entity_decode(trim($item->$v->__toString()));
            }
        }
        return $data;
    }

    private function getParentId($feedId)
    {
        if ($resource = $this->modx->getObject('modResource', ['feed_id' => $feedId])) {
            $id = $resource->get('id');
            unset($resource);
            return $id;
        }

        return $this->manageResource(['feed_id' => $feedId]);
    }

    private function manageResource($data)
    {
        if (empty($data)) {
            $this->log('[ImportFeed::manageResource] Не переданы данные ресурса.', [], true);
        }
        if (!$data['pagetitle']) {
            $data['pagetitle'] = 'Ресурс ' . time();
        } else {
            if ($this->config['createUniquePagetitle']) {
                $data['pagetitle'] .= ' ' . $data['feed_id'];
            }
        }
        foreach ($this->config['truncated'] as $field => $length) {
            if ($length) {
                $data[$field] = $this->truncate($data[$field], $length);
            }
        }

        if ($data['class_key'] === 'msCategory') {
            $conditions = [];
            if(is_array($this->config['categoryConditions'])){
                foreach($this->config['categoryConditions'] as $key => $dataKey){
                    $conditions[$key] = $data[$dataKey];
                }
            }
            else{
                $this->log('[ImportFeed::manageResource] Не указаны условия проверки существования категории.');
                return false;
            }
            if (!$resource = $this->modx->getObject($data['class_key'], $conditions)) {
                $resource = $this->modx->newObject($data['class_key']);
            } else {
                $data['parent'] = $resource->get('parent');
            }
        }

        if ($data['class_key'] === 'msProduct') {
            $conditions = [];
            if(is_array($this->config['productConditions'])){
                foreach($this->config['productConditions'] as $key => $dataKey){
                    $conditions[$key] = $data[$dataKey];
                }
            }
            else{
                $this->log('[ImportFeed::manageResource] Не указаны условия проверки существования товара.');
                return false;
            }

            $c = $this->modx->newQuery($data['class_key']);
            $c->leftJoin('msProductData', 'Data');
            $c->where($conditions);
            $c->prepare();
            $resource = $this->modx->getObject($data['class_key'], $c);
            switch ($this->config['importProductsMode']) {
                case 'create':
                    if (!$resource) {
                        $resource = $this->modx->newObject($data['class_key']);
                    }
                    break;
                case 'update':
                    if ($resource) {
                        $data['parent'] = $resource->get('parent');
                    }
                    break;
                default:
                    if (!$resource) {
                        $resource = $this->modx->newObject($data['class_key']);
                    } else {
                        $data['parent'] = $resource->get('parent');
                    }
                    break;
            }

        }


        if (!$resource) {
            $this->log('[ImportFeed::manageResource] Ресурс не получен.');
            return false;
        }
        if ($this->config['saveAlias'] && $data['url']) {
            $url = explode('/', $data['url']);
            $data['alias'] = $url[count($url) - 1];
        } else {
            $pagetitle = $data['pagetitle'];
            if ($this->config['createUniqueAlias'] && !$this->config['createUniquePagetitle']) {
                $pagetitle .= ' ' . $data['feed_id'];
            }
            $data['alias'] = $resource->get('alias') ?: $this->translit($pagetitle);
        }

        $data['updatedon'] = time();
        $this->log('[ImportFeed::manageResource] Будет обработан ресурс со следующими данными.', $data);
        $resource->fromArray($data, '', 1);
        $resource->save();
        return $resource;

    }

    private function setOptions($resource)
    {
        if (!empty($this->options)) {
            foreach ($this->options as $name => $value) {
                $option = $this->manageOption($name);
                $this->manageCategoryOption($option, $resource);
                $this->manageProductOption($option, $resource, implode(', ', $value));
                unset($option);
            }
        }
    }

    private function manageProductOption($option, $res, $val)
    {
        $this->log("[ImportFeed::manageCategoryOption] Опция для товара.", ['key' => $option->key, 'value' => $val, 'rid' => $res->id]);
        if ($this->modx->getObject('msProductOption', array('product_id' => $res->id, 'key' => $option->key))) {
            $q = $this->modx->newQuery('msProductOption');
            $q->command('UPDATE');
            $q->where(array('key' => $option->key, 'product_id' => $res->id));
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
        $table = $this->modx->getTableName('msCategoryOption');
        if (!$this->modx->getObject('msCategoryOption', array('option_id' => $option->id, 'category_id' => $res->parent))) {
            $sql = "INSERT INTO {$table} (`option_id`,`category_id`,`active`, `required`, `value`) VALUES ({$option->id}, {$res->parent}, 1, 0, '')";
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
            $option->fromArray(array('key' => $key, 'caption' => $name, 'type' => 'textfield', 'category' => $this->config['optionsCategoryId']));
            $option->save();
            $this->log("[ImportFeed::manageOption] Опция {$name} создана.");
        } else {
            $this->log("[ImportFeed::manageOption] Опция {$name} уже существует.");
        }
        return $option;
    }

    private function setGallery($photos, $resource)
    {
        if (empty($photos)) {
            $this->log("[ImportFeed::setGallery] Фотографий для товара нет.");
            return false;
        }
        if (!is_dir($this->imagePath)) {
            mkdir($this->imagePath, 0700, 1);
        }
        $this->log("[ImportFeed::setGallery] Устанавливаем галерею ", $photos);
        if ($this->config['removeOldFiles']) {
            if ($files = $resource->getMany('Files')) {
                foreach ($files as $f) {
                    $f->remove();
                }
                $this->log("[ImportFeed::setGallery] Старые файлы галереи были удалены");
                unset($files);
            }
        }
        foreach ($photos as $url) {
            $this->log("[ImportFeed::setGallery] Обрабатывается фото {$url}");
            $path = $this->imagePath . basename($url);
            if (!file_exists($path)) {
                if ($this->config['allowDownloadImages']) {
                    $path = $this->download($url, $path);
                    $this->log("[ImportFeed::setGallery] Фото загружено на сервер.");
                } else {
                    $this->log("[ImportFeed::setGallery] Фото отсутствует на сервере и не было загружено по инициативе пользователя.");
                    continue;
                }
            }
            if (!$path || !filesize($path)) {
                if (!$path = $this->download($url, $path)) {
                    $this->log("[ImportFeed::setGallery] Фото отсутствует на сервере или является пустым файлом. ");
                    continue;
                }
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
                $this->log("[ImportFeed::setGallery] Не удалось добавить фото в галерею ", $response->getAllErrors());
            } else {
                $this->log("[ImportFeed::setGallery] Фото {$url} успешно добавлено в галерею");
                unlink($path);
            }
            unset($path, $data, $response, $url);
        }
        unset($photos);
    }

    private function startReading($filename, $search, $method)
    {
        if (!$filename) {
            $this->log("[ImportFeed::startReading] Не передано имя файла фида.", [], 1);
        }
        $readingData = [
            'offset' => 0,
        ];
        if (file_exists($this->readingDataPath)) {
            $readingData = json_decode(file_get_contents($this->readingDataPath), 1);
        }

        if ($readingData['offset'] === 0 && $search === 'offer' && $this->config['productDefaultFields']['supplier']) {
            $this->setImportStatus();
        }

        $reader = new XMLReader;
        $success = $reader->open($filename);
        if (!$success) {
            $this->log("[ImportFeed::startReading] Невозможно считать файл $filename. Возможно он содержит ошибки XML.", [], 1);
        }

        $c = 0;
        $end = $readingData['offset'] + $this->config['importStep'];
        while ($reader->read()) {
            if ($reader->name === $search) {
                $xml = $reader->readOuterXML();
                if (strpos($xml, "</$search>") !== false) {
                    $c++;
                    if ($c < $readingData['offset']) continue;
                    if ($c < $end) {
                        $this->log("[ImportFeed::startReading] Импортируем ресурс методом $method с порядковым номером $c");
                        $this->log("[ImportFeed::startReading] Сырые данные $xml");
                        $this->log("[ImportFeed::startReading] memory usage: " . memory_get_usage() / 1048576);
                        $this->$method(new SimpleXMLElement($xml));
                    } else {
                        $readingData['offset'] = $end;
                        file_put_contents($this->readingDataPath, json_encode($readingData));
                        die();
                    }
                    unset($xml);
                }
            }
        }
        $reader->close();
        unset($reader);
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