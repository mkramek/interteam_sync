<?php

use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;

class SyncController extends FrameworkBundleAdminController
{
    protected function addOrSyncPrestaProduct($product_to_sync) {
        // TODO: replace variables with real product data
        $product = new Product(); // Create new product in prestashop
        $product->ean13 = $ean13;
        $product->reference = $ref;
        $product->name = $this->createMultiLangField(utf8_encode($name));
        $product->description = htmlspecialchars($text);
        $product->id_category_default = $catDef;
        $product->redirect_type = '301';
        $product->price = number_format($price, 6, '.', '');
        $product->minimal_quantity = 1;
        $product->show_price = 1;
        $product->on_sale = 0;
        $product->online_only = 0;
        $product->meta_description = '';
        $product->link_rewrite = $this->createMultiLangField(Tools::str2url($name));
        $product->add(); // Submit new product
        StockAvailable::setQuantity($product->id, null, $qty); // id_product, id_product_attribute, quantity
        $product->addToCategories($catAll); // After product is submitted insert all categories

        // Insert "feature name" and "feature value"
        if (is_array($features)) {
            foreach ($features as $feature) {
                $attributeName = $feature['name'];
                $attributeValue = $feature['value'];

                // 1. Check if 'feature name' exist already in database
                $FeatureNameId = Db::getInstance()->getValue('SELECT id_feature FROM ' . _DB_PREFIX_ . 'feature_lang WHERE name = "' . pSQL($attributeName) . '"');
                // If 'feature name' does not exist, insert new.
                if (empty($getFeatureName)) {
                    Db::getInstance()->execute('INSERT INTO `' . _DB_PREFIX_ . 'feature` (`id_feature`,`position`) VALUES (0, 0)');
                    $FeatureNameId = Db::getInstance()->Insert_ID(); // Get id of "feature name" for insert in product
                    Db::getInstance()->execute('INSERT INTO `' . _DB_PREFIX_ . 'feature_shop` (`id_feature`,`id_shop`) VALUES (' . $FeatureNameId . ', 1)');
                    Db::getInstance()->execute('INSERT INTO `' . _DB_PREFIX_ . 'feature_lang` (`id_feature`,`id_lang`, `name`) VALUES (' . $FeatureNameId . ', ' . Context::getContext()->language->id . ', "' . pSQL($attributeName) . '")');
                }

                // 1. Check if 'feature value name' exist already in database
                $FeatureValueId = Db::getInstance()->getValue('SELECT id_feature_value FROM ' . _DB_PREFIX_ . 'feature_value WHERE id_feature_value IN (SELECT id_feature_value FROM `' . _DB_PREFIX_ . 'feature_value_lang` WHERE value = "' . pSQL($attributeValue) . '") AND id_feature = ' . $FeatureNameId);
                // If 'feature value name' does not exist, insert new.
                if (empty($FeatureValueId)) {
                    Db::getInstance()->execute('INSERT INTO `' . _DB_PREFIX_ . 'feature_value` (`id_feature_value`,`id_feature`,`custom`) VALUES (0, ' . $FeatureNameId . ', 0)');
                    $FeatureValueId = Db::getInstance()->Insert_ID();
                    Db::getInstance()->execute('INSERT INTO `' . _DB_PREFIX_ . 'feature_value_lang` (`id_feature_value`,`id_lang`,`value`) VALUES (' . $FeatureValueId . ', ' . Context::getContext()->language->id . ', "' . pSQL($attributeValue) . '")');
                }
                Db::getInstance()->execute('INSERT INTO `' . _DB_PREFIX_ . 'feature_product` (`id_feature`, `id_product`, `id_feature_value`) VALUES (' . $FeatureNameId . ', ' . $product->id . ', ' . $FeatureValueId . ')');
            }
        }

        // add product image.
        $shops = Shop::getShops(true, null, true);
        $image = new Image();
        $image->id_product = $product->id;
        $image->position = Image::getHighestPosition($product->id) + 1;
        $image->cover = true;
        if (($image->validateFields(false, true)) === true && ($image->validateFieldsLang(false, true)) === true && $image->add()) {
            $image->associateTo($shops);
            if (!$this->uploadImage($product->id, $image->id, $imgUrl)) {
                $image->delete();
            }
        }
        echo 'Product added successfully (ID: ' . $product->id . ')';
    }

    protected function uploadImage($id_entity, $id_image = null, $imgUrl) {
        $tmpfile = tempnam(_PS_TMP_IMG_DIR_, 'ps_import');
        $watermark_types = explode(',', Configuration::get('WATERMARK_TYPES'));
        $image_obj = new Image((int)$id_image);
        $path = $image_obj->getPathForCreation();
        $imgUrl = str_replace(' ', '%20', trim($imgUrl));
        // Evaluate the memory required to resize the image: if it's too big we can't resize it.
        if (!ImageManager::checkImageMemoryLimit($imgUrl)) {
            return false;
        }
        if (@copy($imgUrl, $tmpfile)) {
            ImageManager::resize($tmpfile, $path . '.jpg');
            $images_types = ImageType::getImagesTypes('products');
            foreach ($images_types as $image_type) {
                ImageManager::resize($tmpfile, $path . '-' . stripslashes($image_type['name']) . '.jpg', $image_type['width'], $image_type['height']);
                if (in_array($image_type['id_image_type'], $watermark_types)) {
                    Hook::exec('actionWatermark', array('id_image' => $id_image, 'id_product' => $id_entity));
                }
            }
        } else {
            unlink($tmpfile);
            return false;
        }
        unlink($tmpfile);
        return true;
    }

    protected function createMultiLangField($field) {
        $res = array();
        foreach (Language::getIDs(false) as $id_lang) {
            $res[$id_lang] = $field;
        }
        return $res;
    }

    public function sync() {
        $context = stream_context_create([
            'ssl' => [
                // set some SSL/TLS specific options
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ]);

        $opts = [
            'trace' => true,
            'exceptions' => false,
            'local_cert' => __DIR__ . '/cert.pem',
            'passphrase' => '16parts286',
            'stream_context' => $context
        ];

        $login = [
            'id' => Configuration::get('INTERTEAM_SYNC_REC_NO'),
            'password' => Configuration::get('INTERTEAM_SYNC_REC_PASS'),
            'branch' => Configuration::get('INTERTEAM_SYNC_SECTION_NO'),
            'subbuyer' => Configuration::get('INTERTEAM_SYNC_SUB_REC_NO')
        ];

        $client = new SoapClient(Configuration::get('INTERTEAM_SYNC_WSDL_URL'), $opts);

        $response = $client->Manufacturers(['login' => $login]);

        if (is_soap_fault($response)) {
            echo "SOAP FAULT: [{$response->faultcode}] {$response->faultstring}";
        } else {
            var_dump($response);
            $codes = [];
            $products = [];
            foreach ($response->manufacturers as $manufacturer) {
                $codes[] = $manufacturer->manufacturer->code;
            }
            foreach ($codes as $code) {
                $res = $client->Products(['login' => $login, 'manufacturercode' => $code]);
                $products[$res->manufacturercode] = $res->products;
            }
            foreach ($products as $manuCode => $productList) {
                foreach ($productList as $product) {
                    $this->addOrSyncPrestaProduct($product);
                }
            }
        }
    }
}