<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2020-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
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
use FacturaScripts\Core\Lib\ExtendedController\ListController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\ProductType;

/**
 * Description of ReportStock
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class ReportStock extends ListController
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'reports';
        $data['title'] = 'stock';
        $data['icon'] = 'fas fa-dolly';
        return $data;
    }

    protected function createViews()
    {
        $this->createViewsStock();
        $this->createViewsMovements();
        $this->createViewsTransfers();
        $this->createViewsCounting();
    }

    protected function createViewsCounting(string $viewName = 'ListConteoStock'): void
    {
        $this->addView($viewName, 'ConteoStock', 'stock-counts', 'fas fa-scroll')
            ->addOrderBy(['fechainicio'], 'date', 2)
            ->addSearchFields(['idconteo', 'observaciones']);

        // Filters
        $this->addFilterPeriod($viewName, 'fechainicio', 'date', 'fechainicio');
        $warehouses = $this->codeModel->all('almacenes', 'codalmacen', 'nombre');
        $this->addFilterSelect($viewName, 'codalmacen', 'warehouse', 'codalmacen', $warehouses);
        $this->addFilterAutocomplete($viewName, 'nick', 'user', 'nick', 'users', 'nick', 'nick');

        // disable buttons
        $this->setSettings($viewName, 'btnDelete', false);
        $this->setSettings($viewName, 'btnNew', false);
        $this->setSettings($viewName, 'checkBoxes', false);
    }

    protected function createViewsMovements(string $viewName = 'ListMovimientoStock'): void
    {
        $this->addView($viewName, 'MovimientoStock', 'movements', 'fas fa-truck-loading')
            ->addOrderBy(['fecha', 'hora', 'id'], 'date', 2)
            ->addOrderBy(['cantidad'], 'quantity')
            ->addSearchFields(['documento', 'referencia']);

        // Filters
        $this->addFilterPeriod($viewName, 'fecha', 'date', 'fecha');

        $warehouses = $this->codeModel->all('almacenes', 'codalmacen', 'nombre');
        $this->addFilterSelect($viewName, 'codalmacen', 'warehouse', 'codalmacen', $warehouses);

        // disable buttons
        $this->setSettings($viewName, 'btnDelete', false);
        $this->setSettings($viewName, 'btnNew', false);
        $this->setSettings($viewName, 'checkBoxes', false);
    }

    protected function createViewsStock(string $viewName = 'StockProducto'): void
    {
        $this->addView($viewName, 'Join\StockProducto', 'stock', 'fas fa-dolly')
            ->addOrderBy(['referencia', 'codalmacen'], 'reference')
            ->addOrderBy(['disponible'], 'available')
            ->addOrderBy(['cantidad'], 'quantity', 2)
            ->addOrderBy(['total_movimientos'], 'movements')
            ->addOrderBy(['coste'], 'cost-price')
            ->addOrderBy(['total'], 'total')
            ->addSearchFields(['productos.descripcion', 'stocks.referencia']);

        // filters
        $i18n = Tools::lang();
        $values = [
            [
                'label' => $i18n->trans('all'),
                'where' => []
            ],
            [
                'label' => $i18n->trans('under-minimums'),
                'where' => [new DataBaseWhere('disponible', 'field:stockmin', '<')]
            ],
            [
                'label' => $i18n->trans('excess'),
                'where' => [new DataBaseWhere('disponible', 'field:stockmax', '>')]
            ]
        ];
        $this->addFilterSelectWhere($viewName, 'type', $values);

        $this->addFilterSelectWhere($viewName, 'status', [
            ['label' => $i18n->trans('all'), 'where' => []],
            ['label' => $i18n->trans('only-active'), 'where' => [new DataBaseWhere('productos.bloqueado', false)]],
            ['label' => $i18n->trans('blocked'), 'where' => [new DataBaseWhere('productos.bloqueado', true)]],
            ['label' => $i18n->trans('public'), 'where' => [new DataBaseWhere('productos.publico', true)]],
        ]);

        $types = [];
        foreach (ProductType::all() as $key => $key) {
            $types[$key] = $i18n->trans($key);
        }
        $this->addFilterSelect($viewName, 'tipo', 'type', 'tipo', $types);

        $warehouses = $this->codeModel->all('almacenes', 'codalmacen', 'nombre');
        $this->addFilterSelect($viewName, 'codalmacen', 'warehouse', 'codalmacen', $warehouses);

        $manufacturers = $this->codeModel->all('fabricantes', 'codfabricante', 'nombre');
        $this->addFilterSelect($viewName, 'codfabricante', 'manufacturer', 'codfabricante', $manufacturers);

        $families = $this->codeModel->all('familias', 'codfamilia', 'descripcion');
        $this->addFilterSelect($viewName, 'codfamilia', 'family', 'codfamilia', $families);

        $this->addFilterNumber($viewName, 'max-stock', 'quantity', 'cantidad', '>=');
        $this->addFilterNumber($viewName, 'min-stock', 'quantity', 'cantidad', '<=');

        $this->addFilterCheckbox($viewName, 'secompra', 'for-purchase', 'productos.secompra');

        // disable buttons
        $this->setSettings($viewName, 'btnDelete', false);
        $this->setSettings($viewName, 'btnNew', false);
        $this->setSettings($viewName, 'checkBoxes', false);
    }

    protected function createViewsTransfers(string $viewName = 'ListTransferenciaStock'): void
    {
        $this->addView($viewName, 'TransferenciaStock', 'transfers', 'fas fa-exchange-alt')
            ->addOrderBy(['fecha'], 'date', 2)
            ->addSearchFields(['idtrans', 'observaciones']);

        // Filters
        $this->addFilterPeriod($viewName, 'fecha', 'date', 'fecha');
        $warehouses = $this->codeModel->all('almacenes', 'codalmacen', 'nombre');
        $this->addFilterSelect($viewName, 'codalmacenorigen', 'origin-warehouse', 'codalmacenorigen', $warehouses);
        $this->addFilterSelect($viewName, 'codalmacendestino', 'destination-warehouse', 'codalmacendestino', $warehouses);
        $this->addFilterAutocomplete($viewName, 'nick', 'user', 'nick', 'users', 'nick', 'nick');

        // disable buttons
        $this->setSettings($viewName, 'btnDelete', false);
        $this->setSettings($viewName, 'btnNew', false);
        $this->setSettings($viewName, 'checkBoxes', false);
    }
}
