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

namespace FacturaScripts\Plugins\StockAvanzado\Model;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Model\Base;
use FacturaScripts\Dinamic\Model\Almacen;
use FacturaScripts\Dinamic\Model\Stock;

/**
 * Description of ConteoStock
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class ConteoStock extends Base\ModelClass
{
    use Base\ModelTrait;

    /** @var string */
    public $codalmacen;

    /** @var string */
    public $fechafin;

    /** @var string */
    public $fechainicio;

    /** @var int */
    public $idconteo;

    /** @var string */
    public $nick;

    /** @var string */
    public $observaciones;

    public function clear()
    {
        parent::clear();
        $this->fechafin = date(self::DATE_STYLE);
        $this->fechainicio = date(self::DATE_STYLE);
    }

    public function delete(): bool
    {
        foreach ($this->getLines() as $line) {
            $line->delete();
        }

        return parent::delete();
    }

    public function getAlmacen(): Almacen
    {
        $almacen = new Almacen();
        $almacen->loadFromCode($this->codalmacen);
        return $almacen;
    }

    /**
     * @return LineaConteoStock[]
     */
    public function getLines(): array
    {
        $lineaConteo = new LineaConteoStock();
        $where = [new DataBaseWhere('idconteo', $this->idconteo)];
        return $lineaConteo->all($where, ['fecha' => 'DESC'], 0, 0);
    }

    public static function primaryColumn(): string
    {
        return 'idconteo';
    }

    public static function tableName(): string
    {
        return 'stocks_conteos';
    }

    public function test(): bool
    {
        $this->observaciones = $this->toolBox()->utils()->noHtml($this->observaciones);
        return parent::test();
    }

    public function updateStock(): bool
    {
        self::$dataBase->beginTransaction();

        foreach ($this->getLines() as $line) {
            $stockData = [
                'cantidad' => $line->cantidad,
                'codalmacen' => $this->codalmacen,
                'pterecibir' => 0,
                'referencia' => $line->referencia,
                'reservada' => 0
            ];

            $stock = new Stock();
            $where = [
                new DataBaseWhere('codalmacen', $stockData['codalmacen']),
                new DataBaseWhere('referencia', $stockData['referencia'])
            ];
            if ($stock->loadFromCode('', $where)) {
                // el stock ya existe
                $stock->loadFromData($stockData);
                if (false === $stock->save()) {
                    self::$dataBase->rollback();
                    return false;
                }
                continue;
            }

            // creamos y guardamos el stock
            $newStock = new Stock($stockData);
            if (false === $newStock->save()) {
                self::$dataBase->rollback();
                return false;
            }
        }

        self::$dataBase->commit();
        return true;
    }

    public function url(string $type = 'auto', string $list = 'ListAlmacen?activetab=List'): string
    {
        return parent::url($type, $list);
    }
}
