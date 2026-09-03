<?php

namespace manonkindt\csvexport\web\assets\importbutton;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Adds an "Import translations…" button next to "Export…" on the entries index.
 */
class ImportButtonAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        $this->js = ['import-button.js'];

        parent::init();
    }
}
