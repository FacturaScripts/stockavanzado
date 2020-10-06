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
namespace FacturaScripts\Plugins\StockAvanzado\Model;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Model\Base;
use FacturaScripts\Dinamic\Model\Almacen;

/**
 * Description of ConteoStock
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class ConteoStock extends Base\ModelClass
{

    use Base\ModelTrait;

    /**
     *
     * @var string
     */
    public $codalmacen;

    /**
     *
     * @var string
     */
    public $fechafin;

    /**
     *
     * @var string
     */
    public $fechainicio;

    /**
     *
     * @var int
     */
    public $idconteo;

    /**
     *
     * @var string
     */
    public $nick;

    /**
     *
     * @var string
     */
    public $observaciones;

    public function clear()
    {
        parent::clear();
        $this->fechafin = \date(self::DATE_STYLE);
        $this->fechainicio = \date(self::DATE_STYLE);
    }

    /**
     * 
     * @return bool
     */
    public function delete()
    {
        foreach ($this->getLines() as $line) {
            $line->delete();
        }

        return parent::delete();
    }

    /**
     * 
     * @return Almacen
     */
    public function getAlmacen()
    {
        $almacen = new Almacen();
        $almacen->loadFromCode($this->codalmacen);
        return $almacen;
    }

    /**
     * 
     * @return LineaConteoStock[]
     */
    public function getLines()
    {
        $lineaConteo = new LineaConteoStock();
        $where = [new DataBaseWhere('idconteo', $this->idconteo)];
        return $lineaConteo->all($where, ['fecha' => 'DESC'], 0, 0);
    }

    /**
     * 
     * @return string
     */
    public static function primaryColumn(): string
    {
        return 'idconteo';
    }

    /**
     * 
     * @return string
     */
    public static function tableName(): string
    {
        return 'stocks_conteos';
    }

    /**
     * 
     * @return bool
     */
    public function test()
    {
        $this->observaciones = $this->toolBox()->utils()->noHtml($this->observaciones);
        return parent::test();
    }

    /**
     * 
     * @param string $type
     * @param string $list
     *
     * @return string
     */
    public function url(string $type = 'auto', string $list = 'ListAlmacen?activetab=List'): string
    {
        return parent::url($type, $list);
    }
}
