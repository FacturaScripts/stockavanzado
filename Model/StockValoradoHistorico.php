<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2026 Carlos Garcia Gomez <carlos@facturascripts.com>
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

namespace FacturaScripts\Plugins\StockAvanzado\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Almacen;

/**
 * Modelo para histórico de stock valorado
 */
class StockValoradoHistorico extends ModelClass
{
    use ModelTrait;

    /** @var string */
    public $creation_date;

    /** @var string */
    public $codalmacen;

    /** @var int */
    public $id;

    /** @var string */
    public $fecha;

    /** @var float */
    public $total_coste;

    /** @var float */
    public $total_precio;

    public function clear(): void
    {
        parent::clear();
        $this->total_coste = 0.0;
        $this->total_precio = 0.0;
        $this->creation_date = Tools::Date();
    }

    public function install(): string
    {
        // dependencias
        new Almacen();

        return parent::install();
    }

    public static function tableName(): string
    {
        return 'stock_valorado_historico';
    }
}