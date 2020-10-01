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
class ListConteoStock extends ExtendedController\ListController
{

    /**
     * Returns basic page attributes
     *
     * @return array
     */
    public function getPageData()
    {
        $pagedata = parent::getPageData();
        $pagedata['menu'] = 'warehouse';
        $pagedata['title'] = 'stock-counts';
        $pagedata['icon'] = 'fas fa-pallet';

        return $pagedata;
    }

    /**
     * Load views
     */
    protected function createViews()
    {
        $this->addView('ListConteoStock', 'ConteoStock', 'stock-count', 'fas fa-boxes');
        $this->addSearchFields('ListConteoStock', ['codigo']);

        $this->addOrderBy('ListConteoStock', ['fechainicio','horainicio'], 'Fecha Inicio', 2);
        $this->addOrderBy('ListConteoStock', ['fechafin','horafin'], 'Fecha Fin');
        $this->addOrderBy('ListConteoStock', ['editable'], 'Editable');
    }
}
