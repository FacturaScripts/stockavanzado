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

use FacturaScripts\Core\Model\Stock;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Almacen;
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

        // creamos 2 productos
        $product1 = $this->getRandomProduct();
        $this->assertTrue($product1->save());
        $product2 = $this->getRandomProduct();
        $this->assertTrue($product2->save());

        // añadimos stock al almacén
        $stock1 = new Stock();
        $stock1->codalmacen = $warehouse->codalmacen;
        $stock1->referencia = $product1->referencia;
        $stock1->idproducto = $product1->idproducto;
        $stock1->cantidad = 100;
        $this->assertTrue($stock1->save());
        $stock2 = new Stock();
        $stock2->codalmacen = $warehouse->codalmacen;
        $stock2->referencia = $product2->referencia;
        $stock2->idproducto = $product2->idproducto;
        $stock2->cantidad = 25;
        $this->assertTrue($stock2->save());

        // creamos un segundo almacén
        $warehouse2 = $this->getRandomWarehouse();
        $this->assertTrue($warehouse2->save());

        // hacemos una transferencia de stock del almacén 1 al almacén 2
        $transferencia = new TransferenciaStock();
        $transferencia->codalmacenorigen = $warehouse->codalmacen;
        $transferencia->codalmacendestino = $warehouse2->codalmacen;
        $transferencia->observaciones = 'Transferencia de stock test';
        $this->assertTrue($transferencia->save());

        // añadimos el producto 1 a la transferencia
        $lineaTrans1 = $transferencia->addLine($product1->referencia, $product1->idproducto, 10);
        $this->assertTrue($lineaTrans1->exists());

        // añadimos el producto 2 a la transferencia
        $lineaTrans2 = $transferencia->addLine($product2->referencia, $product2->idproducto, 5);
        $this->assertTrue($lineaTrans2->exists());

        // comprobamos que no se ha alterado el stock del almacén 1
        $stock1->load($stock1->idstock);
        $this->assertEquals(100, $stock1->cantidad);
        $stock2->load($stock2->idstock);
        $this->assertEquals(25, $stock2->cantidad);

        // ejecutamos la transferencia
        $this->assertTrue($transferencia->transferStock());

        // si intento volver a ejecutarlo debe devolver true porque ya está completado
        $this->assertTrue($transferencia->transferStock());

        // comprobamos que la transferencia está completada
        $transferencia->load($transferencia->idtrans);
        $this->assertTrue($transferencia->completed);

        // comprobamos que está el movimiento de stock del almacén 1
        $movement1 = new MovimientoStock();
        $where1 = [
            Where::column('codalmacen', $transferencia->codalmacenorigen),
            Where::column('docid', $transferencia->id()),
            Where::column('docmodel', $transferencia->modelClassName()),
            Where::column('referencia', $lineaTrans1->referencia)
        ];
        $this->assertTrue($movement1->loadWhere($where1));

        // comprobamos la cantidad del movimiento 1
        $this->assertEquals(-10, $movement1->cantidad);

        // comprobamos el saldo del movimiento 1
        $this->assertEquals(-10, $movement1->saldo);

        // comprobamos que está el movimiento de stock del almacén 2
        $movement2 = new MovimientoStock();
        $where2 = [
            Where::column('codalmacen', $transferencia->codalmacendestino),
            Where::column('docid', $transferencia->id()),
            Where::column('docmodel', $transferencia->modelClassName()),
            Where::column('referencia', $lineaTrans1->referencia)
        ];
        $this->assertTrue($movement2->loadWhere($where2));

        // comprobamos la cantidad del movimiento 2
        $this->assertEquals(10, $movement2->cantidad);

        // comprobamos el saldo del movimiento 2
        $this->assertEquals(10, $movement2->saldo);

        // comprobamos que el stock del producto 1 en almacén 1 ahora es 90
        $stock1->load($stock1->idstock);
        $this->assertEquals(90, $stock1->cantidad);

        // comprobamos que el stock del producto 2 en almacén 1 ahora es 20
        $stock2->load($stock2->idstock);
        $this->assertEquals(20, $stock2->cantidad);

        // comprobamos que el stock del producto 1 en almacén 2 ahora es 10
        $stock21 = new Stock();
        $where21 = [
            Where::column('codalmacen', $warehouse2->codalmacen),
            Where::column('referencia', $product1->referencia)
        ];
        $this->assertTrue($stock21->loadWhere($where21));
        $this->assertEquals(10, $stock21->cantidad);

        // comprobamos que el stock del producto 2 en almacén 2 ahora es 5
        $stock22 = new Stock();
        $where22 = [
            Where::column('codalmacen', $warehouse2->codalmacen),
            Where::column('referencia', $product2->referencia)
        ];
        $this->assertTrue($stock22->loadWhere($where22));
        $this->assertEquals(5, $stock22->cantidad);

        // eliminamos la transferencia
        $this->assertTrue($transferencia->delete());

        // comprobamos que la línea se ha eliminado
        $this->assertFalse($lineaTrans1->exists());

        // comprobamos que el movimiento de stock del almacén 1 se ha eliminado
        $this->assertFalse($movement1->exists());

        // comprobamos que el movimiento de stock del almacén 2 se ha eliminado
        $this->assertFalse($movement2->exists());

        // comprobamos que el stock del almacén 1 vuelve a ser 100
        $stock1->load($stock1->idstock);
        $this->assertEquals(100, $stock1->cantidad);

        // comprobamos que el stock del almacén 2 vuelve a ser 25
        $stock2->load($stock2->idstock);
        $this->assertEquals(25, $stock2->cantidad);

        // comprobamos que el stock del producto 1 en el almacén 2 ahora es 0
        $stock21->load($stock21->idstock);
        $this->assertEquals(0, $stock21->cantidad);

        // comprobamos que el stock del producto 2 en el almacén 2 ahora es 0
        $stock22->load($stock22->idstock);
        $this->assertEquals(0, $stock22->cantidad);

        // eliminamos
        $this->assertTrue($warehouse2->delete());
        $this->assertTrue($warehouse->delete());
        $this->assertTrue($product1->delete());
        $this->assertTrue($product2->delete());
    }

    public function testCreateWithConteo(): void
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
        $transferencia->load($transferencia->idtrans);
        $this->assertTrue($transferencia->completed);

        // comprobamos que está el movimiento de stock del almacén 1
        $movement1 = new MovimientoStock();
        $where1 = [
            Where::column('codalmacen', $transferencia->codalmacenorigen),
            Where::column('docid', $transferencia->id()),
            Where::column('docmodel', $transferencia->modelClassName()),
            Where::column('referencia', $lineaTrans->referencia)
        ];
        $this->assertTrue($movement1->loadWhere($where1));

        // comprobamos que está el movimiento de stock del almacén 2
        $movement2 = new MovimientoStock();
        $where2 = [
            Where::column('codalmacen', $transferencia->codalmacendestino),
            Where::column('docid', $transferencia->id()),
            Where::column('docmodel', $transferencia->modelClassName()),
            Where::column('referencia', $lineaTrans->referencia)
        ];
        $this->assertTrue($movement2->loadWhere($where2));

        // comprobamos que el stock del almacén 1 es 90
        $stock = new Stock();
        $where = [
            Where::column('codalmacen', $warehouse->codalmacen),
            Where::column('referencia', $product->referencia)
        ];
        $this->assertTrue($stock->loadWhere($where));
        $this->assertEquals(90, $stock->cantidad);

        // comprobamos que el stock del almacén 2 es 10
        $stock2 = new Stock();
        $where2 = [
            Where::column('codalmacen', $warehouse2->codalmacen),
            Where::column('referencia', $product->referencia)
        ];
        $this->assertTrue($stock2->loadWhere($where2));
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
        $stock->load($stock->idstock);
        $this->assertEquals(100, $stock->cantidad);

        // comprobamos que el stock del almacén 2 es 0
        $stock2->load($stock2->idstock);
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

    public function testCantTransferWithoutStock(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un segundo almacén
        $warehouse2 = $this->getRandomWarehouse();
        $this->assertTrue($warehouse2->save());

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // creamos una transferencia de stock
        $transferencia = new TransferenciaStock();
        $transferencia->codalmacenorigen = $warehouse->codalmacen;
        $transferencia->codalmacendestino = $warehouse2->codalmacen;
        $transferencia->observaciones = 'Transferencia sin stock test';
        $this->assertTrue($transferencia->save());

        // añadimos el producto a la transferencia
        $lineaTrans = $transferencia->addLine($product->referencia, $product->idproducto, 10);
        $this->assertTrue($lineaTrans->exists());

        // ejecutamos la transferencia
        $this->assertFalse($transferencia->transferStock());

        // comprobamos que la transferencia no está completada
        $transferencia->load($transferencia->idtrans);
        $this->assertFalse($transferencia->completed);

        // comprobamos que no hay stock en el almacén de origen
        $stock = new Stock();
        $where = [
            Where::column('codalmacen', $warehouse->codalmacen),
            Where::column('referencia', $product->referencia)
        ];
        $this->assertFalse($stock->loadWhere($where));

        // comprobamos que no hay stock en el almacén de destino
        $stock2 = new Stock();
        $where2 = [
            Where::column('codalmacen', $warehouse2->codalmacen),
            Where::column('referencia', $product->referencia)
        ];
        $this->assertFalse($stock2->loadWhere($where2));

        // eliminamos
        $this->assertTrue($transferencia->delete());
        $this->assertTrue($warehouse2->delete());
        $this->assertTrue($warehouse->delete());
        $this->assertTrue($product->delete());
    }

    public function testCantTransferBetweenCompanies(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos una empresa
        $company2 = $this->getRandomCompany();
        $this->assertTrue($company2->save());

        // cargamos el almacén de la empresa 2
        $warehouse2 = new Almacen();
        foreach ($company2->getWarehouses() as $w) {
            $warehouse2 = $w;
            break;
        }

        // creamos una transferencia de stock entre empresas
        $transferencia = new TransferenciaStock();
        $transferencia->codalmacenorigen = $warehouse->codalmacen;
        $transferencia->codalmacendestino = $warehouse2->codalmacen;
        $transferencia->observaciones = 'Transferencia entre empresas test';
        $this->assertFalse($transferencia->save());

        // eliminamos
        $this->assertTrue($warehouse->delete());
        $this->assertTrue($company2->delete());
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
