<?php

use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;

class AdminController extends FrameworkBundleAdminController {
    
	public function initContent() {
		$this->setTemplate('module:interteam_sync/views/templates/admin/sync.tpl');
	}
	
}