<?php

error_reporting(E_ALL ^ E_NOTICE ^ E_WARNING);

include 'vendor/autoload.php';
$copy = new Codeeshop\PsModuleOcmod\XmlModifier(_PS_ROOT_DIR_, _PS_MODULE_DIR_);
print_r('<center>================== Done ==================</center>');
?>