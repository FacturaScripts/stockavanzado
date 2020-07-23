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

/**
 * Description of ListAlmacen
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class ListAlmacen
{

    protected function createViews()
    {
        return function() {
            $this->createViewsTransfers();
        };
    }

    protected function createViewsTransfers()
    {
        return function($viewName = 'ListTransferenciaStock') {
            $this->addView($viewName, 'TransferenciaStock', 'stock-transfers', 'fas fa-exchange-alt');
            $this->addSearchFields($viewName, ['observaciones']);
            $this->addOrderBy($viewName, ['codalmacenorigen'], 'origin-warehouse');
            $this->addOrderBy($viewName, ['codalmacendestino'], 'destination-warehouse');
            $this->addOrderBy($viewName, ['fecha'], 'date');
            $this->addOrderBy($viewName, ['usuario'], 'user');

            /// Filters
            $this->addFilterDatePicker($viewName, 'fromfecha', 'from-date', 'fecha', '>=');
            $this->addFilterDatePicker($viewName, 'untilfecha', 'until-date', 'fecha', '<=');
            $this->addFilterAutocomplete($viewName, 'nick', 'user', 'nick', 'users', 'nick', 'nick');
        };
    }
}
