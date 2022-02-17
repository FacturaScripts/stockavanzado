<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2020-2022 Carlos Garcia Gomez <carlos@facturascripts.com>
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
use FacturaScripts\Core\Base\ToolBox;
use FacturaScripts\Core\DataSrc\Almacenes;
use FacturaScripts\Dinamic\Model\Stock;
use FacturaScripts\Plugins\StockAvanzado\Model\ConteoStock;
use FacturaScripts\Plugins\StockAvanzado\Model\LineaConteoStock;

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
        return function () {
            $this->createViewsMovements();
            $this->views['EditStock']->disableColumn('quantity', false, 'true');
        };
    }

    protected function createViewsMovements()
    {
        return function ($viewName = 'ListMovimientoStock') {
            $this->addListView($viewName, 'MovimientoStock', 'movements', 'fas fa-truck-loading');
            $this->views[$viewName]->addOrderBy(['fecha', 'hora', 'id'], 'date', 2);
            $this->views[$viewName]->addOrderBy(['cantidad'], 'quantity');
            $this->views[$viewName]->searchFields = ['documento', 'referencia'];

            // disable product column
            $this->views[$viewName]->disableColumn('product');

            // disable warehouse column or add warehouse filter
            if (Almacenes::codeModel(false) <= 1) {
                $this->views[$viewName]->disableColumn('warehouse');
            } else {
                $this->views[$viewName]->addFilterSelect('codalmacen', 'warehouse', 'codalmacen', Almacenes::codeModel());
            }

            // disable buttons
            $this->setSettings($viewName, 'btnDelete', false);
            $this->setSettings($viewName, 'btnNew', false);
            $this->setSettings($viewName, 'checkBoxes', false);
        };
    }

    protected function execPreviousAction()
    {
        return function ($action) {
            if ($action === 'change-stock') {
                return $this->changeStockAction();
            }

            return true;
        };
    }


    protected function loadData()
    {
        return function ($viewName, $view) {
            if ($viewName !== 'ListMovimientoStock') {
                return;
            }

            $idproducto = $this->getViewModelValue('EditProducto', 'idproducto');
            $where = [new DataBaseWhere('idproducto', $idproducto)];
            $view->loadData('', $where);
            $this->setSettings($viewName, 'active', $view->model->count($where) > 0);
        };
    }

    protected function changeStockAction()
    {
        return function () {
            $data = $this->request->request->all();

            $stock = new Stock();
            if (empty($data['code']) || false === $stock->loadFromCode($data['code'])) {
                ToolBox::i18nLog()->warning('record-not-found');
                return true;
            }

            $this->dataBase->beginTransaction();

            // creamos un nuevo conteo
            $conteo = new ConteoStock();
            $conteo->nick = $this->user->nick;
            $conteo->codalmacen = $stock->codalmacen;
            $conteo->observaciones = $data['mov-description'];
            if (false === $conteo->save()) {
                $this->dataBase->rollback();
                ToolBox::i18nLog()->warning('record-save-error');
                return true;
            }

            // añadimos una línea con la nueva cantidad
            $line = new LineaConteoStock();
            $line->idconteo = $conteo->idconteo;
            $line->idproducto = $stock->idproducto;
            $line->referencia = $stock->referencia;
            $line->cantidad = (float)$data['mov-quantity'];
            if (false === $line->save()) {
                $this->dataBase->rollback();
                ToolBox::i18nLog()->warning('record-save-error');
                return true;
            }

            // actualizamos el stock
            $stock->cantidad = (float)$data['mov-quantity'];
            if (false === $stock->save()) {
                $this->dataBase->rollback();
                ToolBox::i18nLog()->warning('record-save-error');
                return true;
            }

            $this->dataBase->commit();
            ToolBox::i18nLog()->notice('record-updated-correctly');
            return true;
        };
    }
}
