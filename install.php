<?php
// install.php

defined('_JEXEC') or die;

use Joomla\CMS\Factory;

/**
 * Script file for SpectroCoin/VirtueMart payment plugin
 */
class PlgVmPaymentSpectrocoinInstallerScript
{
    /**
     * Called after successful uninstall.
     *
     * @param   \Joomla\CMS\Installer\InstallerAdapter  $adapter
     * @return  void
     */
    public function uninstall($adapter): void
    {
        $db = Factory::getDbo();

        $db->setQuery(
            $db->getQuery(true)
               ->delete($db->quoteName('#__extensions'))
               ->where($db->quoteName('type')   . ' = ' . $db->quote('plugin'))
               ->where($db->quoteName('folder') . ' = ' . $db->quote('vmpayment'))
               ->where($db->quoteName('element'). ' = ' . $db->quote('spectrocoin'))
        )->execute();

        $db->setQuery(
            $db->getQuery(true)
               ->delete($db->quoteName('#__virtuemart_paymentmethods'))
               ->where($db->quoteName('payment_element') . ' = ' . $db->quote('spectrocoin'))
        )->execute();

        $db->setQuery('DROP TABLE IF EXISTS ' . $db->quoteName('#__virtuemart_payment_plg_spectrocoin'))
           ->execute();
    }
}
