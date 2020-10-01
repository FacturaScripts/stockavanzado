<?php
/**
 * This file is part of Inventory plugin for FacturaScripts
 * Copyright (C) 2020 Juan José Prieto Dzul <juanjoseprieto88@gmail.com>
 */
namespace FacturaScripts\Plugins\ConteoInventario\Controller;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Lib\ExtendedController;
use FacturaScripts\Plugins\ConteoInventario\Model\ConteoStock;

/**
 * Controller to edit a single item from the Divisa model
 *
 * @author Juan José Prieto Dzul <juanjoseprieto88@gmail.com>
 */
class EditConteoStock extends ExtendedController\EditController
{

    /**
     * Returns the model name
     */
    public function getModelClassName()
    {
        return 'ConteoStock';
    }


    /**
     * Returns basic page attributes
     *
     * @return array
     */
    public function getPageData()
    {
        $pagedata = parent::getPageData();
        $pagedata['menu'] = 'warehouse';
        $pagedata['title'] = 'stock-count';
        $pagedata['icon'] = 'fas fa-clipboard-list';
        $pagedata['showonmenu'] = false;

        return $pagedata;
    }

    protected function createViews()
    {
        parent::createViews();
        $this->setTabsPosition('bottom');

        /// more buttons
        $this->createCustomButtons();

        /// more tabs
        $this->createViewRecords();
    }

    protected function execPreviousAction($action)
    {
        switch ($action) {
            case 'commit-stock':
                $this->commitStockUpdate();
                $this->toolBox()->log()->info('stock-updated');
                return true;

            default:
                return parent::execPreviousAction($action);
        }
    }

    protected function loadData($viewName, $view)
    {
        $idconteo = $this->getViewModelValue($this->getMainViewName(), 'idconteo');
        switch ($viewName) {
            case 'EditConteoStock':
                parent::loadData($viewName, $view);
                $nick = $this->getModel()->nick;

                if (empty($nick)) {
                    $view->model->nick = $this->user->nick;
                }
                break;

            case 'ListLineaConteoStock':
                $where = [new DataBaseWhere('idconteo', $idconteo)];
                $view->loadData('', $where);
                break;

            default:
                parent::loadData($viewName, $view);
                break;
        }
    }

    /**
     *
     * @param string $viewName
     */
    protected function createViewRecords(string $viewName = 'ListLineaConteoStock')
    {
        $this->addListView($viewName, 'LineaConteoStock', 'records', 'fas fa-cubes');
    }

    protected function createCustomButtons()
    {
        $commitButton = [
            'action' => 'commit-stock',
            'color' => 'warning',
            'confirm' => true,
            'icon' => 'fas fa-clipboard-check',
            'label' => 'Confirmar Stock',
        ];
        $this->addButton($this->getMainViewName(), $commitButton);

        $pickUpButton = [
            'action' => 'CapturaInventario',
            'color' => 'primary',
            'confirm' => true,
            'icon' => 'fas fa-clipboard-check',
            'label' => 'Conteo rapido',
            'type' => 'link'
        ];
        $this->addButton($this->getMainViewName(), $pickUpButton);
    }

    private function commitStockUpdate()
    {
        $idconteo = $this->getViewModelValue($this->getMainViewName(), 'idconteo');

        $conteo = new ConteoStock();
        $conteo->loadFromCode($idconteo);

        foreach ($conteo->getLines() as $linea) {

        }
    }
}
