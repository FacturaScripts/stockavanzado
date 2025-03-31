<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2022-2025 Carlos Garcia Gomez <carlos@facturascripts.com>
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

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Model\Stock;
use FacturaScripts\Dinamic\Model\ConteoStock;
use FacturaScripts\Dinamic\Model\LineaConteoStock;
use FacturaScripts\Dinamic\Model\LineaTransferenciaStock;
use FacturaScripts\Dinamic\Model\MovimientoStock;
use FacturaScripts\Dinamic\Model\TransferenciaStock;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use FacturaScripts\Test\Traits\RandomDataTrait;
use PHPUnit\Framework\TestCase;

final class TransferenciaStockTest extends TestCase
{
    use LogErrorsTrait;
    use RandomDataTrait;

    public function testCreate(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // añadimos un conteo inicial
        $conteo = new ConteoStock();
        $conteo->codalmacen = $warehouse->codalmacen;
        $conteo->observaciones = 'Conteo inicial';
        $this->assertTrue($conteo->save());

        // añadimos el producto al conteo de stock
        $lineaConteo = $conteo->addLine($product->referencia, $product->idproducto, 100);
        $this->assertTrue($lineaConteo->exists());

        // ejecutamos el conteo
        $this->assertTrue($conteo->updateStock());

        // creamos un segundo almacén
        $warehouse2 = $this->getRandomWarehouse();
        $this->assertTrue($warehouse2->save());

        // hacemos una transferencia de stock del almacén 1 al almacén 2
        $transferencia = new TransferenciaStock();
        $transferencia->codalmacenorigen = $warehouse->codalmacen;
        $transferencia->codalmacendestino = $warehouse2->codalmacen;
        $transferencia->observaciones = 'Transferencia de stock test';
        $this->assertTrue($transferencia->save());

        // añadimos el producto a la transferencia
        $lineaTrans = $transferencia->addLine($product->referencia, $product->idproducto, 10);
        $this->assertTrue($lineaTrans->exists());

        // ejecutamos la transferencia
        $this->assertTrue($transferencia->transferStock());

        // si intento volver a ejecutarlo debe devolver true porque ya está completado
        $this->assertTrue($transferencia->transferStock());

        // comprobamos que la transferencia está completada
        $transferencia->loadFromCode($transferencia->idtrans);
        $this->assertTrue($transferencia->completed);

        // comprobamos que está el movimiento de stock del almacén 1
        $movement1 = new MovimientoStock();
        $where1 = [
            new DataBaseWhere('codalmacen', $transferencia->codalmacenorigen),
            new DataBaseWhere('docid', $transferencia->primaryColumnValue()),
            new DataBaseWhere('docmodel', $transferencia->modelClassName()),
            new DataBaseWhere('referencia', $lineaTrans->referencia)
        ];
        $this->assertTrue($movement1->loadFromCode('', $where1));

        // comprobamos que está el movimiento de stock del almacén 2
        $movement2 = new MovimientoStock();
        $where2 = [
            new DataBaseWhere('codalmacen', $transferencia->codalmacendestino),
            new DataBaseWhere('docid', $transferencia->primaryColumnValue()),
            new DataBaseWhere('docmodel', $transferencia->modelClassName()),
            new DataBaseWhere('referencia', $lineaTrans->referencia)
        ];
        $this->assertTrue($movement2->loadFromCode('', $where2));

        // comprobamos que el stock del almacén 1 es 90
        $stock = new Stock();
        $where = [
            new DataBaseWhere('codalmacen', $warehouse->codalmacen),
            new DataBaseWhere('referencia', $product->referencia)
        ];
        $this->assertTrue($stock->loadFromCode('', $where));
        $this->assertEquals(90, $stock->cantidad);

        // comprobamos que el stock del almacén 2 es 10
        $stock2 = new Stock();
        $where2 = [
            new DataBaseWhere('codalmacen', $warehouse2->codalmacen),
            new DataBaseWhere('referencia', $product->referencia)
        ];
        $this->assertTrue($stock2->loadFromCode('', $where2));
        $this->assertEquals(10, $stock2->cantidad);

        // eliminamos la transferencia
        $this->assertTrue($transferencia->delete());

        // comprobamos que la línea se ha eliminado
        $this->assertFalse($lineaTrans->exists());

        // comprobamos que el movimiento de stock del almacén 1 se ha eliminado
        $this->assertFalse($movement1->exists());

        // comprobamos que el movimiento de stock del almacén 2 se ha eliminado
        $this->assertFalse($movement2->exists());

        // comprobamos que el stock del almacén 1 es 100
        $stock->loadFromCode($stock->idstock);
        $this->assertEquals(100, $stock->cantidad);

        // comprobamos que el stock del almacén 2 es 0
        $stock2->loadFromCode($stock2->idstock);
        $this->assertEquals(0, $stock2->cantidad);

        // eliminamos
        $this->assertTrue($warehouse2->delete());
        $this->assertTrue($warehouse->delete());
        $this->assertTrue($product->delete());
    }

    public function testCantTransferToSameWarehouse(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos una transferencia de stock con el mismo almacén de origen y destino
        $transferencia = new TransferenciaStock();
        $transferencia->codalmacenorigen = $warehouse->codalmacen;
        $transferencia->codalmacendestino = $warehouse->codalmacen;
        $this->assertFalse($transferencia->save());

        // eliminamos
        $this->assertTrue($warehouse->delete());
    }

    public function testHtmlOnFields(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un segundo almacén
        $warehouse2 = $this->getRandomWarehouse();
        $this->assertTrue($warehouse2->save());

        // creamos una transferencia de stock con html en las observaciones
        $transferencia = new TransferenciaStock();
        $transferencia->codalmacenorigen = $warehouse->codalmacen;
        $transferencia->codalmacendestino = $warehouse2->codalmacen;
        $transferencia->observaciones = '<test>';
        $this->assertTrue($transferencia->save());

        // comprobamos que el html se ha escapado
        $this->assertEquals('&lt;test&gt;', $transferencia->observaciones);

        // eliminamos
        $this->assertTrue($transferencia->delete());
        $this->assertTrue($warehouse->delete());
        $this->assertTrue($warehouse2->delete());
    }

    protected function tearDown(): void
    {
        $this->logErrors();
    }
}
