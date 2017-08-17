## NetCents payment module for Magento 1.x.
Supports Magento 1.4.2.0 up to 1.9.3.x.


## Installation
> **NOTE** Before you begin, make a backup of your Magento site.

* Using Manual Installation:
    * Click the Download Zip button and save to your local machine
    * Transfer the zip file to your Magento webserver
    * Unpack the archive in the root directory of your Magento instance
    * Flush your Magento caches
        * In the admin page for your Magento instance, navigate to System->Cache Management
        * Click the 'Flush Magento Cache'
        * More information on Magento Cache Management [here](http://www.magentocommerce.com/knowledge-base/entry/cache-storage-management)
    * Log out of the admin page and then log back in to ensure activation of the module


## Uninstall/Disable

   * From the command line edit the files: 
      * `<magento_root>/app/etc/modules/Liftmode_NetCents.xml`
   * In each file, change the line
      `<active>true</active>`
      to
      `<active>false</active>`
   * This will completely disable the extension and prevent any resources being loaded by Magento.


## Configure Magento
* The plugin is configured under **System**->**Configuration**->**Payment Methods**->**NetCents**.


## Support
You can create issues or if you have some specific problems for your account you can contact <a href="mailto:dema501@gmail.com">dema501@gmail.com</a> as well.


## Authors and license

[Dmitry Bashlov], contributors
MIT License, see the included [License.md](License.md) file.
