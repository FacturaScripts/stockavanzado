<?php
/**
 * This file is part of Inventory plugin for FacturaScripts
 * Copyright (C) 2020 Juan José Prieto Dzul <juanjoseprieto88@gmail.com>
 */
namespace FacturaScripts\Plugins\ConteoInventario\Controller;

use FacturaScripts\Core\Lib\ExtendedController;

/**
 * Controller to list the items in the SesionPOS model
 *
 * @author Juan José Prieto Dzul <juanjoseprieto88@gmail.com>
 */
class ListLineaConteoStock extends ExtendedController\ListController
{

    /**
     * Returns basic page attributes
     *
     * @return array
     */
    public function getPageData()
    {
        $pagedata = parent::getPageData();
        $pagedata['menu'] = 'inventory';
        $pagedata['title'] = 'inventory-records';
        $pagedata['icon'] = 'fas fa-boxes';
        $pagedata['showonmenu'] = false;

        return $pagedata;
    }

    /**
     * Load views
     */
    protected function createViews()
    {
        $this->addView('ListLineaConteoStock', 'LineaConteoStock', 'inventory-records', 'fas fa-boxes');
        $this->addSearchFields('ListLineaConteoStock', ['referencia']);

        $this->addOrderBy('ListLineaConteoStock', ['fechaconteo','horaconteo'], 'Hora de registro', 2);
        $this->addOrderBy('ListLineaConteoStock', ['pendiente'], 'Pendiente');
    }
}
