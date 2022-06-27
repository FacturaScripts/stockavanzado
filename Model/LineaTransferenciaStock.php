<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2014-2022 Carlos Garcia Gomez <carlos@facturascripts.com>
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
use FacturaScripts\Dinamic\Model\Stock;
use FacturaScripts\Dinamic\Model\Variante;
use FacturaScripts\Plugins\StockAvanzado\Lib\StockMovementManager;

/**
 * Transfers stock lines.
 *
 * @author Cristo M. Estévez Hernández  <cristom.estevez@gmail.com>
 * @author Carlos Garcia Gomez          <carlos@facturascripts.com>
 */
class LineaTransferenciaStock extends Base\ModelOnChangeClass
{

    use Base\ModelTrait;
    use Base\ProductRelationTrait;

    /**
     * Quantity of product transfered
     *
     * @var float|int
     */
    public $cantidad;

    /**
     * @var bool
     */
    private static $disableUpdateStock = false;

    /**
     * Primary key of line transfer stock. Autoincremental
     *
     * @var int
     */
    public $idlinea;

    /**
     * Foreign key with head of this transfer line.
     *
     * @var int
     */
    public $idtrans;

    /**
     * @var string
     */
    public $referencia;

    public function clear()
    {
        parent::clear();
        $this->cantidad = 1.0;
    }

    public function getTransference(): TransferenciaStock
    {
        $trans = new TransferenciaStock();
        $trans->loadFromCode($this->idtrans);
        return $trans;
    }

    public function getVariant(): Variante
    {
        $variant = new Variante();
        $where = [new DataBaseWhere('referencia', $this->referencia)];
        $variant->loadFromCode('', $where);
        return $variant;
    }

    public function install(): string
    {
        // needed dependencies
        new TransferenciaStock();
        new Variante();

        return parent::install();
    }

    public static function primaryColumn(): string
    {
        return 'idlinea';
    }

    public static function setDisableUpdateStock(bool $disable)
    {
        self::$disableUpdateStock = $disable;
    }

    public function test(): bool
    {
        $this->referencia = $this->toolBox()->utils()->noHtml($this->referencia);
        if (empty($this->idproducto)) {
            $variant = $this->getVariant();
            $this->idproducto = $variant->idproducto;
        }

        return parent::test();
    }

    public static function tableName(): string
    {
        return 'stocks_lineastransferencias';
    }

    /**
     * This methos is called before save (update) when some field value has changes.
     *
     * @param string $field
     *
     * @return bool
     */
    protected function onChange($field)
    {
        if ($field == 'cantidad') {
            return $this->updateStock($this->cantidad - $this->previousData['cantidad']);
        }

        return parent::onChange($field);
    }

    /**
     * This method is called after remove this data from the database.
     */
    protected function onDelete()
    {
        $this->cantidad = 0.0;
        $this->updateStock($this->previousData['cantidad'] * -1);
    }

    protected function saveInsert(array $values = []): bool
    {
        return $this->updateStock($this->cantidad) && parent::saveInsert($values);
    }

    protected function setPreviousData(array $fields = [])
    {
        $more = ['cantidad'];
        parent::setPreviousData(\array_merge($more, $fields));
    }

    protected function updateStock(float $quantity): bool
    {
        if (self::$disableUpdateStock) {
            return true;
        }

        $transfer = $this->getTransference();
        $stock = new Stock();
        $where = [
            new DataBaseWhere('codalmacen', $transfer->codalmacenorigen),
            new DataBaseWhere('referencia', $this->referencia)
        ];
        if (false === $stock->loadFromCode('', $where) || $stock->cantidad < $quantity) {
            $this->toolBox()->i18nLog()->warning('not-enough-stock', ['%reference%' => $this->referencia]);
            return false;
        }

        if ($stock->transferTo($transfer->codalmacendestino, $quantity)) {
            StockMovementManager::updateLineTransfer($this, $transfer);
            return true;
        }

        return false;
    }
}
