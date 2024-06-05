<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2017-2023 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Plugins\StockAvanzado\Controller;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Lib\ExtendedController\BaseView;
use FacturaScripts\Core\Lib\ExtendedController\EditController;

/**
 * Controller to edit a transfer of stock
 *
 * @author Cristo M. Estévez Hernández  <cristom.estevez@gmail.com>
 * @author Carlos García Gómez          <carlos@facturascripts.com>
 */
class EditTransferenciaStock extends EditController
{
    public function getModelClassName(): string
    {
        return 'TransferenciaStock';
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'warehouse';
        $data['title'] = 'stock-transfer';
        $data['icon'] = 'fas fa-exchange-alt';
        return $data;
    }

    /**
     * Load views
     */
    protected function createViews()
    {
        parent::createViews();
        $this->setTabsPosition('bottom');

        $this->createViewsLines();
    }

    protected function createViewsLines(string $viewName = 'EditLineaTransferenciaStock'): void
    {
        $this->addEditListView($viewName, 'LineaTransferenciaStock', 'lines')
            ->setInline(true);
    }

    /**
     * Load view data procedure
     *
     * @param string $viewName
     * @param BaseView $view
     */
    protected function loadData($viewName, $view)
    {
        $mvn = $this->getMainViewName();

        switch ($viewName) {
            case $mvn:
                parent::loadData($viewName, $view);
                break;

            case 'EditLineaTransferenciaStock':
                $id = $this->getViewModelValue($mvn, 'idtrans');
                $where = [new DataBaseWhere('idtrans', $id)];
                $view->loadData('', $where, ['idlinea' => 'DESC']);
                break;
        }
    }
}
