<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2020-2025 Carlos Garcia Gomez <carlos@facturascripts.com>
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

use Closure;
use FacturaScripts\Core\DataSrc\Almacenes;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Lib\StockMovementManager;
use FacturaScripts\Dinamic\Lib\StockRebuild;
use FacturaScripts\Dinamic\Model\ConteoStock;
use FacturaScripts\Dinamic\Model\LineaConteoStock;
use FacturaScripts\Dinamic\Model\Stock;

/**
 * Description of EditProducto
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 * @author Jose Antonio Cuello <yopli2000@gmail.com>
 */
class EditProducto
{
    protected function changeStockAction(): Closure
    {
        return function () {
            $data = $this->request->request->all();

            $stock = new Stock();
            if (empty($data['code']) || false === $stock->load($data['code'])) {
                Tools::log()->warning('record-not-found');
                return true;
            }

            $this->dataBase->beginTransaction();

            // creamos un nuevo conteo
            $conteo = new ConteoStock();
            $conteo->codalmacen = $stock->codalmacen;
            $conteo->observaciones = $data['mov-description'];
            if (false === $conteo->save()) {
                $this->dataBase->rollback();
                Tools::log()->warning('record-save-error');
                return true;
            }

            // añadimos una línea con la nueva cantidad
            $line = $conteo->addLine($stock->referencia, $stock->idproducto, (float)$data['mov-quantity']);
            if (empty($line->id())) {
                $this->dataBase->rollback();
                Tools::log()->warning('record-save-error');
                return true;
            }

            // ejecutamos el conteo
            if (false === $conteo->updateStock()) {
                $this->dataBase->rollback();
                Tools::log()->warning('record-save-error');
                return true;
            }

            $this->dataBase->commit();
            Tools::log()->notice('record-updated-correctly');
            return true;
        };
    }

    protected function createViews(): Closure
    {
        return function () {
            // marcamos la columna de cantidad en el stock como no editable
            $this->tab('EditStock')->disableColumn('quantity', false, 'true');

            // añadimos las nuevas pestañas
            $this->createViewsMovements();
        };
    }

    protected function createViewsMovements(): Closure
    {
        return function ($viewName = 'ListMovimientoStock') {
            $this->addListView($viewName, 'MovimientoStock', 'movements', 'fa-solid fa-truck-loading')
                ->addOrderBy(['fecha', 'hora', 'id'], 'date', 2)
                ->addOrderBy(['cantidad'], 'quantity')
                ->addSearchFields(['documento', 'referencia'])
                ->disableColumn('product')
                ->setSettings('btnDelete', false)
                ->setSettings('btnNew', false)
                ->setSettings('checkBoxes', false);

            // filtros
            $this->listView($viewName)->addFilterPeriod('fecha', 'date', 'fecha')
                ->addFilterNumber('cantidadgt', 'quantity', 'cantidad', '>=')
                ->addFilterNumber('cantidadlt', 'quantity', 'cantidad', '<=');

            // desactivamos la columna de almacén si solo hay uno
            if (count(Almacenes::codeModel(false)) <= 1) {
                $this->listView($viewName)->disableColumn('warehouse');
            } else {
                $this->listView($viewName)->addFilterSelect('codalmacen', 'warehouse', 'codalmacen', Almacenes::codeModel());
            }

            // desactivamos los botones de nuevo, eliminar y checkbox


            if ($this->user->admin) {
                $this->addButton($viewName, [
                    'action' => 'rebuild-movements',
                    'color' => 'warning',
                    'confirm' => true,
                    'icon' => 'fa-solid fa-repeat',
                    'label' => 'rebuild-movements'
                ]);

                $this->addButton($viewName, [
                    'action' => 'rebuild-stock',
                    'color' => 'warning',
                    'confirm' => true,
                    'icon' => 'fa-solid fa-dolly',
                    'label' => 'rebuild-stock'
                ]);
            }
        };
    }

    protected function execPreviousAction(): Closure
    {
        return function ($action) {
            switch ($action) {
                case 'change-stock':
                    $this->changeStockAction();
                    break;

                case 'rebuild-movements':
                    $this->rebuildMovementsAction();
                    break;

                case 'rebuild-stock':
                    $this->rebuildStockAction();
                    break;
            }
        };
    }

    protected function loadData(): Closure
    {
        return function ($viewName, $view) {
            $id = $this->getViewModelValue('EditProducto', 'idproducto');

            if ($viewName == 'ListMovimientoStock') {
                $where = [Where::column('idproducto', $id)];
                $view->loadData('', $where);
                $view->setSettings('active', $view->model->count($where) > 0);
            }
        };
    }

    protected function rebuildMovementsAction(): Closure
    {
        return function () {
            if (false === $this->user->admin) {
                Tools::log()->warning('not-allowed-modify');
                return;
            } elseif (false === $this->validateFormToken()) {
                return;
            }

            $product = $this->getModel();
            if (false === $product->load($this->request->get('code'))) {
                return;
            }

            StockMovementManager::rebuild($product->idproducto);
            Tools::log()->notice('rebuilt-movements');
        };
    }

    protected function rebuildStockAction(): Closure
    {
        return function () {
            if (false === $this->user->admin) {
                Tools::log()->warning('not-allowed-modify');
                return;
            } elseif (false === $this->validateFormToken()) {
                return;
            }

            $product = $this->getModel();
            if (false === $product->load($this->request->get('code'))) {
                return;
            }

            StockRebuild::rebuild($product->idproducto);
            Tools::log()->notice('rebuilt-stock');
        };
    }
}
