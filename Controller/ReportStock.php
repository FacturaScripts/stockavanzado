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
namespace FacturaScripts\Plugins\StockAvanzado\Controller;

use FacturaScripts\Core\Lib\ExtendedController\ListController;

/**
 * Description of ReportStock
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class ReportStock extends ListController
{

    /**
     * 
     * @return array
     */
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
    }

    /**
     * 
     * @param string $viewName
     */
    protected function createViewsStock(string $viewName = 'StockProducto')
    {
        $this->addView($viewName, 'Join\StockProducto', 'stock', 'fas fa-dolly');
        $this->addOrderBy($viewName, ['disponible'], 'available');
        $this->addOrderBy($viewName, ['cantidad'], 'quantity', 2);
        $this->addOrderBy($viewName, ['coste'], 'cost-price');
        $this->addOrderBy($viewName, ['total'], 'total');
        $this->addSearchFields($viewName, ['productos.descripcion', 'stocks.referencia']);

        /// filters
        $warehouses = $this->codeModel->all('almacenes', 'codalmacen', 'nombre');
        $this->addFilterSelect($viewName, 'codalmacen', 'warehouse', 'codalmacen', $warehouses);

        $manufacturers = $this->codeModel->all('fabricantes', 'codfabricante', 'nombre');
        $this->addFilterSelect($viewName, 'codfabricante', 'manufacturer', 'codfabricante', $manufacturers);

        $families = $this->codeModel->all('familias', 'codfamilia', 'descripcion');
        $this->addFilterSelect($viewName, 'codfamilia', 'family', 'codfamilia', $families);

        /// disable buttons
        $this->setSettings($viewName, 'btnDelete', false);
        $this->setSettings($viewName, 'btnNew', false);
        $this->setSettings($viewName, 'checkBoxes', false);
        $this->setSettings($viewName, 'clickable', false);
    }
}
