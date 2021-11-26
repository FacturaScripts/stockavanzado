<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2020 Carlos Garcia Gomez <carlos@facturascripts.com>
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
namespace FacturaScripts\Plugins\StockAvanzado\Extension\Controller;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Dinamic\Model\Almacen;
use FacturaScripts\Dinamic\Model\Stock;
use FacturaScripts\Dinamic\Model\TotalModel;
use FacturaScripts\Plugins\StockAvanzado\Model\ConteoStock;
use FacturaScripts\Plugins\StockAvanzado\Model\LineaConteoStock;
use FacturaScripts\Plugins\StockAvanzado\Model\MovimientoStock;

/**
 * Description of EditProducto
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 * @author Jose Antonio Cuello <yopli2000@gmail.com>
 */
class EditProducto
{

    protected function createViews()
    {
        return function() {
            $this->createViewsMovements();
            $this->views['EditStock']->disableColumn('quantity', false, 'true');
        };
    }

    protected function createViewsMovements()
    {
        return function($viewName = 'ListMovimientoStock') {
            $this->addListView($viewName, 'MovimientoStock', 'movements', 'fas fa-truck-loading');
            $this->views[$viewName]->addOrderBy(['fecha', 'hora', 'id'], 'date', 2);
            $this->views[$viewName]->addOrderBy(['cantidad'], 'quantity');
            $this->views[$viewName]->searchFields = ['documento', 'referencia'];

            /// disable column
            $this->views[$viewName]->disableColumn('product');
            $almacen = new Almacen();
            if ($almacen->count() <= 1) {
                $this->views[$viewName]->disableColumn('warehouse');
            }

            /// disable buttons
            $this->setSettings($viewName, 'btnDelete', false);
            $this->setSettings($viewName, 'btnNew', false);
            $this->setSettings($viewName, 'checkBoxes', false);
        };
    }

    protected function execPreviousAction() {
        return function($action) {
            if ($action === '') {
                if ($this->changeStock()) {
                    $this->toolBox()->i18nLog()->notice('stock-changed');
                }
            }
        };
    }


    protected function loadData()
    {
        return function($viewName, $view) {
            if ($viewName !== 'ListMovimientoStock') {
                return;
            }

            $idproducto = $this->getViewModelValue('EditProducto', 'idproducto');
            $where = [new DataBaseWhere('idproducto', $idproducto)];
            $view->loadData('', $where);
            $this->setSettings($viewName, 'active', $view->model->count($where) > 0);
        };
    }

    private function changeStock()
    {
        $data = $this->request->request->all();
        $idstock = $data['idstock'] ?? -1;

        $stock = new Stock();
        if (false === $stock->loadFromCode($idstock)) {
            return false;
        }

        $header = new ConteoStock();
        $this->dataBase->beginTransaction();
        try {
            if (false === $this->saveHeader($stock, $header, $data['mov-description']) ||
                false === $this->saveLine($stock, $header->idconteo, $data['mov-quantity']))
            {
                return false;
            }

            $quantity = TotalModel::sum(MovimientoStock::tableName(), 'cantidad', [
                    new DataBaseWhere('codalmacen', $stock->codalmacen),
                    new DataBaseWhere('referencia', $stock->referencia),
                ]
            );

            $stock->cantidad = $quantity;
            if (false === $stock->save()) {
                return false;
            }

            $this->dataBase->commit();
        } finally {
            if ($this->dataBase->inTransaction()) {
                $this->dataBase->rollback();
            }
        }

        return true;
    }

    private function saveHeader($stock, &$header, $notes): bool
    {
        $header->nick = $this->user->nick;
        $header->codalmacen = $stock->codalmacen;
        $header->observaciones = $notes;
        if (false === $header->save()) {
            return false;
        }
    }

    private function saveLine($stock, $idconteo, $quantity): bool
    {
        $line = new LineaConteoStock();
        $line->idconteo = $idconteo;
        $line->idproducto = $stock->idproducto;
        $line->referencia = $stock->referencia;
        $line->cantidad = $quantity;
        return $line->save();
    }
}
