# Composer installer
> this plugin is marge "**composer/installers**" "**yiisoft/yii2-composer**"


#### Current Supported Package Types

| Types
| -----
| `pixelion-theme`
| `pixelion-module`
| `pixelion-widget`
| `pixelion-component`
| `pixelion-theme-custom`
| `pixelion-module-custom`
| `pixelion-widget-custom`
| `pixelion-component-custom`



composer.json
```
    "extra": {
        "panix\\composer\\Installer\\Installer::postCreateProject": {
            "createDir": [
                {
                    "web/uploads/content": "0755"
                }
            ]
        }
    },
```
