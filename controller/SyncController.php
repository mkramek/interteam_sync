<?php

use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;

class SyncController extends FrameworkBundleAdminController {
    
	private $data_dir = __DIR__ . "/../Data";
	
	public function sync() {
		$products = [];
		$first = true;
		if (($handle = fopen(__DIR__ . "$data_dir/it16286.csv", "r")) !== FALSE) {
			while(!feof($handle)) {
				if ($first) {
					$first = false;
					continue;
				}
				$data = explode(";", stream_get_line($handle, 4096, "\n"));
				$products[] = [
					'id_prod' => $data[0] ?? '',
					'id_manuf' => $data[1] ?? '',
					'id_tecdoc' => $data[2] ?? '',
					'id_prod_manuf' => $data[3] ?? '',
					'name' => $data[4] ?? '',
					'unit' => $data[5] ?? '',
					'price' => $data[6] ?? '',
					'price_pre' => $data[7] ?? '',
					'gtu' => $data[9] ?? '',
				];
			}
			fclose($handle);
		}
		array_shift($products);
		foreach ($products as $product) {
			addOrSyncPrestaProduct($product);
		}
	}
	
	private function addOrSyncPrestaProduct($product_to_sync) {
		// TODO: replace variables with real product data
		$product = new Product($product_to_sync['id_prod']); // Create new product in prestashop
		// $product->ean13 = $ean13;
		// $product->reference = $ref;
		$product->name = createMultiLangField(utf8_encode($product_to_sync['name']));
		// $product->description = htmlspecialchars($text);
		// $product->redirect_type = '301';
		$product->price = number_format($product_to_sync['price'], 6, '.', '');
		$product->minimal_quantity = 1;
		$product->show_price = 1;
		$product->on_sale = 0;
		$product->online_only = 0;
		$product->meta_description = '';
		$product->link_rewrite = createMultiLangField(Tools::str2url($product_to_sync['name']));
		$product->add(); // Submit new product
		StockAvailable::setQuantity($product->id, null, $qty); // id_product, id_product_attribute, quantity
		$product->addToCategories($catAll); // After product is submitted insert all categories
		$features = [
			[
				'name' => 'id_tecdoc',
				'value' => $product_to_sync['id_tecdoc']
			],
			[
				'name' => 'gtu',
				'value' => $product_to_sync['gtu']
			],
			[
				'name' => 'kaucja',
				'value' => $product_to_sync['price_pre']
			]
		];
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
					Db::getInstance()->execute('INSERT INTO `' . _DB_PREFIX_ . 'feature_lang` (`id_feature`,`id_lang`, `name`) VALUES (' . $FeatureNameId . ', ' . Context:$
				}
			
				// 1. Check if 'feature value name' exist already in database
				$FeatureValueId = Db::getInstance()->getValue('SELECT id_feature_value FROM ' . _DB_PREFIX_ . 'feature_value WHERE id_feature_value IN (SELECT id_feature_v$
				// If 'feature value name' does not exist, insert new.
				if (empty($FeatureValueId)) {
					Db::getInstance()->execute('INSERT INTO `' . _DB_PREFIX_ . 'feature_value` (`id_feature_value`,`id_feature`,`custom`) VALUES (0, ' . $FeatureNameId . '$
					$FeatureValueId = Db::getInstance()->Insert_ID();
					Db::getInstance()->execute('INSERT INTO `' . _DB_PREFIX_ . 'feature_value_lang` (`id_feature_value`,`id_lang`,`value`) VALUES (' . $FeatureValueId . ',$
				}
				Db::getInstance()->execute('INSERT INTO `' . _DB_PREFIX_ . 'feature_product` (`id_feature`, `id_product`, `id_feature_value`) VALUES (' . $FeatureNameId . $
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
			if (!uploadImage($product->id, $image->id, $imgUrl)) {
				$image->delete();
			}
		}
		echo 'Product added successfully (ID: ' . $product->id . ')';
	}

	function uploadImage($id_entity, $id_image = null, $imgUrl) {
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

	private function createMultiLangField($field) {
		$res = array();
		foreach (Language::getIDs(false) as $id_lang) {
			$res[$id_lang] = $field;
		}
		return $res;
	}

}