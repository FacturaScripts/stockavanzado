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
            Where::eq('codalmacen', $transferencia->codalmacenorigen),
            Where::eq('docid', $transferencia->id()),
            Where::eq('docmodel', $transferencia->modelClassName()),
            Where::eq('referencia', $lineaTrans1->referencia)
        ];
        $this->assertTrue($movement1->loadWhere($where1));

        // comprobamos la cantidad del movimiento 1
        $this->assertEquals(-10, $movement1->cantidad);

        // comprobamos el saldo del movimiento 1
        $this->assertEquals(-10, $movement1->saldo);

        // comprobamos que está el movimiento de stock del almacén 2
        $movement2 = new MovimientoStock();
        $where2 = [
            Where::eq('codalmacen', $transferencia->codalmacendestino),
            Where::eq('docid', $transferencia->id()),
            Where::eq('docmodel', $transferencia->modelClassName()),
            Where::eq('referencia', $lineaTrans1->referencia)
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
            Where::eq('codalmacen', $warehouse2->codalmacen),
            Where::eq('referencia', $product1->referencia)
        ];
        $this->assertTrue($stock21->loadWhere($where21));
        $this->assertEquals(10, $stock21->cantidad);

        // comprobamos que el stock del producto 2 en almacén 2 ahora es 5
        $stock22 = new Stock();
        $where22 = [
            Where::eq('codalmacen', $warehouse2->codalmacen),
            Where::eq('referencia', $product2->referencia)
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
            Where::eq('codalmacen', $transferencia->codalmacenorigen),
            Where::eq('docid', $transferencia->id()),
            Where::eq('docmodel', $transferencia->modelClassName()),
            Where::eq('referencia', $lineaTrans->referencia)
        ];
        $this->assertTrue($movement1->loadWhere($where1));

        // comprobamos que está el movimiento de stock del almacén 2
        $movement2 = new MovimientoStock();
        $where2 = [
            Where::eq('codalmacen', $transferencia->codalmacendestino),
            Where::eq('docid', $transferencia->id()),
            Where::eq('docmodel', $transferencia->modelClassName()),
            Where::eq('referencia', $lineaTrans->referencia)
        ];
        $this->assertTrue($movement2->loadWhere($where2));

        // comprobamos que el stock del almacén 1 es 90
        $stock = new Stock();
        $where = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ];
        $this->assertTrue($stock->loadWhere($where));
        $this->assertEquals(90, $stock->cantidad);

        // comprobamos que el stock del almacén 2 es 10
        $stock2 = new Stock();
        $where2 = [
            Where::eq('codalmacen', $warehouse2->codalmacen),
            Where::eq('referencia', $product->referencia)
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

    public function testSaldoAccumulatesAcrossTransfers(): void
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

        // añadimos stock al almacén de origen
        $stock = new Stock();
        $stock->codalmacen = $warehouse->codalmacen;
        $stock->referencia = $product->referencia;
        $stock->idproducto = $product->idproducto;
        $stock->cantidad = 100;
        $this->assertTrue($stock->save());

        // primera transferencia: 10 unidades del almacén 1 al almacén 2
        $transferencia1 = new TransferenciaStock();
        $transferencia1->codalmacenorigen = $warehouse->codalmacen;
        $transferencia1->codalmacendestino = $warehouse2->codalmacen;
        $transferencia1->fecha = date('Y-m-d H:i:s', strtotime('-1 minute'));
        $transferencia1->observaciones = 'Primera transferencia';
        $this->assertTrue($transferencia1->save());

        $linea1 = $transferencia1->addLine($product->referencia, $product->idproducto, 10);
        $this->assertTrue($linea1->exists());
        $this->assertTrue($transferencia1->transferStock());

        // segunda transferencia: 7 unidades más del almacén 1 al almacén 2
        $transferencia2 = new TransferenciaStock();
        $transferencia2->codalmacenorigen = $warehouse->codalmacen;
        $transferencia2->codalmacendestino = $warehouse2->codalmacen;
        $transferencia2->observaciones = 'Segunda transferencia';
        $this->assertTrue($transferencia2->save());

        $linea2 = $transferencia2->addLine($product->referencia, $product->idproducto, 7);
        $this->assertTrue($linea2->exists());
        $this->assertTrue($transferencia2->transferStock());

        // comprobamos el movimiento de la primera transferencia en el almacén de origen
        $movOrig1 = new MovimientoStock();
        $this->assertTrue($movOrig1->loadWhere([
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('docid', $transferencia1->id()),
            Where::eq('docmodel', $transferencia1->modelClassName()),
            Where::eq('referencia', $product->referencia)
        ]));
        $this->assertEquals(-10, $movOrig1->cantidad);
        $this->assertEquals(-10, $movOrig1->saldo);

        // comprobamos el movimiento de la segunda transferencia en el almacén de origen
        // saldo acumulado: -10 + -7 = -17
        $movOrig2 = new MovimientoStock();
        $this->assertTrue($movOrig2->loadWhere([
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('docid', $transferencia2->id()),
            Where::eq('docmodel', $transferencia2->modelClassName()),
            Where::eq('referencia', $product->referencia)
        ]));
        $this->assertEquals(-7, $movOrig2->cantidad);
        $this->assertEquals(-17, $movOrig2->saldo);

        // comprobamos el movimiento de la primera transferencia en el almacén de destino
        $movDest1 = new MovimientoStock();
        $this->assertTrue($movDest1->loadWhere([
            Where::eq('codalmacen', $warehouse2->codalmacen),
            Where::eq('docid', $transferencia1->id()),
            Where::eq('docmodel', $transferencia1->modelClassName()),
            Where::eq('referencia', $product->referencia)
        ]));
        $this->assertEquals(10, $movDest1->cantidad);
        $this->assertEquals(10, $movDest1->saldo);

        // comprobamos el movimiento de la segunda transferencia en el almacén de destino
        // saldo acumulado: 10 + 7 = 17
        $movDest2 = new MovimientoStock();
        $this->assertTrue($movDest2->loadWhere([
            Where::eq('codalmacen', $warehouse2->codalmacen),
            Where::eq('docid', $transferencia2->id()),
            Where::eq('docmodel', $transferencia2->modelClassName()),
            Where::eq('referencia', $product->referencia)
        ]));
        $this->assertEquals(7, $movDest2->cantidad);
        $this->assertEquals(17, $movDest2->saldo);

        // comprobamos los stocks finales: origen 100 - 17 = 83, destino 17
        $stock->load($stock->idstock);
        $this->assertEquals(83, $stock->cantidad);

        $stockDest = new Stock();
        $this->assertTrue($stockDest->loadWhere([
            Where::eq('codalmacen', $warehouse2->codalmacen),
            Where::eq('referencia', $product->referencia)
        ]));
        $this->assertEquals(17, $stockDest->cantidad);

        // eliminamos
        $this->assertTrue($transferencia2->delete());
        $this->assertTrue($transferencia1->delete());
        $this->assertTrue($warehouse2->delete());
        $this->assertTrue($warehouse->delete());
        $this->assertTrue($product->delete());
    }

    public function testDeleteUncompletedTransfer(): void
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

        // añadimos stock al almacén de origen
        $stock = new Stock();
        $stock->codalmacen = $warehouse->codalmacen;
        $stock->referencia = $product->referencia;
        $stock->idproducto = $product->idproducto;
        $stock->cantidad = 50;
        $this->assertTrue($stock->save());

        // creamos una transferencia y añadimos una línea, pero NO la ejecutamos
        $transferencia = new TransferenciaStock();
        $transferencia->codalmacenorigen = $warehouse->codalmacen;
        $transferencia->codalmacendestino = $warehouse2->codalmacen;
        $transferencia->observaciones = 'Transferencia no ejecutada test';
        $this->assertTrue($transferencia->save());

        $linea = $transferencia->addLine($product->referencia, $product->idproducto, 10);
        $this->assertTrue($linea->exists());

        // comprobamos que no está completada
        $this->assertFalse($transferencia->completed);

        // eliminamos la transferencia sin haberla ejecutado
        $this->assertTrue($transferencia->delete());

        // la línea debe haberse eliminado
        $this->assertFalse($linea->exists());

        // el stock del almacén de origen no debe haberse alterado
        $stock->load($stock->idstock);
        $this->assertEquals(50, $stock->cantidad);

        // no debe existir stock en el almacén de destino
        $stockDest = new Stock();
        $this->assertFalse($stockDest->loadWhere([
            Where::eq('codalmacen', $warehouse2->codalmacen),
            Where::eq('referencia', $product->referencia)
        ]));

        // no debe existir ningún movimiento de stock para esta transferencia
        $movement = new MovimientoStock();
        $this->assertFalse($movement->loadWhere([
            Where::eq('docid', $transferencia->id()),
            Where::eq('docmodel', $transferencia->modelClassName())
        ]));

        // eliminamos
        $this->assertTrue($warehouse2->delete());
        $this->assertTrue($warehouse->delete());
        $this->assertTrue($product->delete());
    }

    public function testCantAddLineForNostockProduct(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un segundo almacén
        $warehouse2 = $this->getRandomWarehouse();
        $this->assertTrue($warehouse2->save());

        // creamos un producto que no gestiona stock
        $product = $this->getRandomProduct();
        $product->nostock = true;
        $this->assertTrue($product->save());

        // creamos una transferencia
        $transferencia = new TransferenciaStock();
        $transferencia->codalmacenorigen = $warehouse->codalmacen;
        $transferencia->codalmacendestino = $warehouse2->codalmacen;
        $transferencia->observaciones = 'Transferencia producto nostock test';
        $this->assertTrue($transferencia->save());

        // intentamos añadir el producto nostock a la transferencia, debe fallar
        $linea = $transferencia->addLine($product->referencia, $product->idproducto, 5);
        $this->assertFalse($linea->exists());

        // la transferencia no debe tener líneas
        $this->assertCount(0, $transferencia->getLines());

        // eliminamos
        $this->assertTrue($transferencia->delete());
        $this->assertTrue($warehouse2->delete());
        $this->assertTrue($warehouse->delete());
        $this->assertTrue($product->delete());
    }

    public function testCantTransferIfProductTurnsNostockAfterAddLine(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un segundo almacén
        $warehouse2 = $this->getRandomWarehouse();
        $this->assertTrue($warehouse2->save());

        // creamos un producto que sí gestiona stock
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // añadimos stock al almacén de origen
        $stock = new Stock();
        $stock->codalmacen = $warehouse->codalmacen;
        $stock->referencia = $product->referencia;
        $stock->idproducto = $product->idproducto;
        $stock->cantidad = 30;
        $this->assertTrue($stock->save());

        // creamos una transferencia y añadimos la línea (todavía nostock=false)
        $transferencia = new TransferenciaStock();
        $transferencia->codalmacenorigen = $warehouse->codalmacen;
        $transferencia->codalmacendestino = $warehouse2->codalmacen;
        $transferencia->observaciones = 'Transferencia con cambio nostock';
        $this->assertTrue($transferencia->save());

        $linea = $transferencia->addLine($product->referencia, $product->idproducto, 5);
        $this->assertTrue($linea->exists());

        // ahora marcamos el producto como nostock
        $product->nostock = true;
        $this->assertTrue($product->save());

        // ejecutar la transferencia debe fallar
        $this->assertFalse($transferencia->transferStock());

        // la transferencia no debe estar completada
        $transferencia->load($transferencia->idtrans);
        $this->assertFalse($transferencia->completed);

        // el stock del almacén de origen no debe haberse alterado
        $stock->load($stock->idstock);
        $this->assertEquals(30, $stock->cantidad);

        // no debe existir stock en el almacén de destino
        $stockDest = new Stock();
        $this->assertFalse($stockDest->loadWhere([
            Where::eq('codalmacen', $warehouse2->codalmacen),
            Where::eq('referencia', $product->referencia)
        ]));

        // no debe existir ningún movimiento de stock para esta transferencia
        $movement = new MovimientoStock();
        $this->assertFalse($movement->loadWhere([
            Where::eq('docid', $transferencia->id()),
            Where::eq('docmodel', $transferencia->modelClassName())
        ]));

        // eliminamos
        $this->assertTrue($transferencia->delete());
        $this->assertTrue($warehouse2->delete());
        $this->assertTrue($warehouse->delete());
        $this->assertTrue($product->delete());
    }

    public function testAddLineConsolidatesSameReference(): void
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

        // añadimos stock al almacén de origen
        $stock = new Stock();
        $stock->codalmacen = $warehouse->codalmacen;
        $stock->referencia = $product->referencia;
        $stock->idproducto = $product->idproducto;
        $stock->cantidad = 50;
        $this->assertTrue($stock->save());

        // creamos una transferencia
        $transferencia = new TransferenciaStock();
        $transferencia->codalmacenorigen = $warehouse->codalmacen;
        $transferencia->codalmacendestino = $warehouse2->codalmacen;
        $transferencia->observaciones = 'Consolidacion lineas test';
        $this->assertTrue($transferencia->save());

        // primera llamada: 4 unidades
        $linea1 = $transferencia->addLine($product->referencia, $product->idproducto, 4);
        $this->assertTrue($linea1->exists());
        $this->assertEquals(4, $linea1->cantidad);

        // segunda llamada con la misma referencia: 6 unidades adicionales
        $linea2 = $transferencia->addLine($product->referencia, $product->idproducto, 6);
        $this->assertTrue($linea2->exists());

        // debe ser la misma línea consolidada con 4 + 6 = 10
        $this->assertEquals($linea1->idlinea, $linea2->idlinea);
        $this->assertEquals(10, $linea2->cantidad);

        // solo debe haber una línea en la transferencia
        $this->assertCount(1, $transferencia->getLines());

        // ejecutamos la transferencia y comprobamos que se transfieren 10 unidades
        $this->assertTrue($transferencia->transferStock());

        $stock->load($stock->idstock);
        $this->assertEquals(40, $stock->cantidad);

        $stockDest = new Stock();
        $this->assertTrue($stockDest->loadWhere([
            Where::eq('codalmacen', $warehouse2->codalmacen),
            Where::eq('referencia', $product->referencia)
        ]));
        $this->assertEquals(10, $stockDest->cantidad);

        // eliminamos
        $this->assertTrue($transferencia->delete());
        $this->assertTrue($warehouse2->delete());
        $this->assertTrue($warehouse->delete());
        $this->assertTrue($product->delete());
    }

    public function testCantModifyCompletedTransfer(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un segundo almacén
        $warehouse2 = $this->getRandomWarehouse();
        $this->assertTrue($warehouse2->save());

        // creamos dos productos
        $product1 = $this->getRandomProduct();
        $this->assertTrue($product1->save());
        $product2 = $this->getRandomProduct();
        $this->assertTrue($product2->save());

        // añadimos stock al almacén de origen para ambos productos
        $stock1 = new Stock();
        $stock1->codalmacen = $warehouse->codalmacen;
        $stock1->referencia = $product1->referencia;
        $stock1->idproducto = $product1->idproducto;
        $stock1->cantidad = 30;
        $this->assertTrue($stock1->save());
        $stock2 = new Stock();
        $stock2->codalmacen = $warehouse->codalmacen;
        $stock2->referencia = $product2->referencia;
        $stock2->idproducto = $product2->idproducto;
        $stock2->cantidad = 30;
        $this->assertTrue($stock2->save());

        // creamos una transferencia con producto1 y la ejecutamos
        $transferencia = new TransferenciaStock();
        $transferencia->codalmacenorigen = $warehouse->codalmacen;
        $transferencia->codalmacendestino = $warehouse2->codalmacen;
        $transferencia->observaciones = 'Transferencia completada test';
        $this->assertTrue($transferencia->save());

        $linea = $transferencia->addLine($product1->referencia, $product1->idproducto, 5);
        $this->assertTrue($linea->exists());
        $this->assertTrue($transferencia->transferStock());

        // recargamos la transferencia y confirmamos que está completada
        $transferencia->load($transferencia->idtrans);
        $this->assertTrue($transferencia->completed);

        // 1) addLine de un producto nuevo debe fallar
        $nuevaLinea = $transferencia->addLine($product2->referencia, $product2->idproducto, 3);
        $this->assertFalse($nuevaLinea->exists());
        $this->assertCount(1, $transferencia->getLines());

        // 2) addLine sobre la misma referencia tampoco debe modificar la línea existente
        $linea->load($linea->idlinea);
        $cantidadOriginal = $linea->cantidad;
        $reintento = $transferencia->addLine($product1->referencia, $product1->idproducto, 7);
        $this->assertFalse($reintento->exists());
        $linea->load($linea->idlinea);
        $this->assertEquals($cantidadOriginal, $linea->cantidad);

        // 3) guardar directamente una modificación sobre la línea existente debe fallar
        $linea->cantidad = 99;
        $this->assertFalse($linea->save());
        $linea->load($linea->idlinea);
        $this->assertEquals($cantidadOriginal, $linea->cantidad);

        // 4) eliminar directamente la línea de una transferencia completada debe fallar
        $this->assertFalse($linea->delete());
        $this->assertTrue($linea->exists());

        // borrar la transferencia entera sí debe seguir funcionando (revierte stock y movimientos)
        $this->assertTrue($transferencia->delete());
        $this->assertFalse($linea->exists());

        $stock1->load($stock1->idstock);
        $this->assertEquals(30, $stock1->cantidad);

        // eliminamos
        $this->assertTrue($warehouse2->delete());
        $this->assertTrue($warehouse->delete());
        $this->assertTrue($product1->delete());
        $this->assertTrue($product2->delete());
    }

    public function testCantTransferWithoutLines(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un segundo almacén
        $warehouse2 = $this->getRandomWarehouse();
        $this->assertTrue($warehouse2->save());

        // creamos una transferencia sin líneas
        $transferencia = new TransferenciaStock();
        $transferencia->codalmacenorigen = $warehouse->codalmacen;
        $transferencia->codalmacendestino = $warehouse2->codalmacen;
        $transferencia->observaciones = 'Transferencia sin lineas test';
        $this->assertTrue($transferencia->save());

        // ejecutar la transferencia debe fallar
        $this->assertFalse($transferencia->transferStock());

        // la transferencia no debe estar completada
        $transferencia->load($transferencia->idtrans);
        $this->assertFalse($transferencia->completed);

        // eliminamos
        $this->assertTrue($transferencia->delete());
        $this->assertTrue($warehouse2->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testTransferVariant(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un segundo almacén
        $warehouse2 = $this->getRandomWarehouse();
        $this->assertTrue($warehouse2->save());

        // creamos una variante (con su propio producto detrás)
        $variant = $this->getRandomVariant();
        $this->assertTrue($variant->save());

        // añadimos stock al almacén de origen sobre la referencia de la variante
        $stock = new Stock();
        $stock->codalmacen = $warehouse->codalmacen;
        $stock->referencia = $variant->referencia;
        $stock->idproducto = $variant->idproducto;
        $stock->cantidad = 20;
        $this->assertTrue($stock->save());

        // creamos una transferencia
        $transferencia = new TransferenciaStock();
        $transferencia->codalmacenorigen = $warehouse->codalmacen;
        $transferencia->codalmacendestino = $warehouse2->codalmacen;
        $transferencia->observaciones = 'Transferencia de variante test';
        $this->assertTrue($transferencia->save());

        // añadimos la variante a la transferencia sin pasar idproducto (debe resolverse desde la variante)
        $linea = $transferencia->addLine($variant->referencia, 0, 8);
        $this->assertTrue($linea->exists());
        $this->assertEquals($variant->idproducto, $linea->idproducto);

        // ejecutamos la transferencia
        $this->assertTrue($transferencia->transferStock());

        // el stock del almacén de origen baja a 12
        $stock->load($stock->idstock);
        $this->assertEquals(12, $stock->cantidad);

        // el almacén de destino tiene 8 unidades de la variante
        $stockDest = new Stock();
        $this->assertTrue($stockDest->loadWhere([
            Where::eq('codalmacen', $warehouse2->codalmacen),
            Where::eq('referencia', $variant->referencia)
        ]));
        $this->assertEquals(8, $stockDest->cantidad);
        $this->assertEquals($variant->idproducto, $stockDest->idproducto);

        // existe el movimiento en origen con la cantidad y referencia correctas
        $movOrig = new MovimientoStock();
        $this->assertTrue($movOrig->loadWhere([
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('docid', $transferencia->id()),
            Where::eq('docmodel', $transferencia->modelClassName()),
            Where::eq('referencia', $variant->referencia)
        ]));
        $this->assertEquals(-8, $movOrig->cantidad);
        $this->assertEquals($variant->idproducto, $movOrig->idproducto);

        // existe el movimiento en destino
        $movDest = new MovimientoStock();
        $this->assertTrue($movDest->loadWhere([
            Where::eq('codalmacen', $warehouse2->codalmacen),
            Where::eq('docid', $transferencia->id()),
            Where::eq('docmodel', $transferencia->modelClassName()),
            Where::eq('referencia', $variant->referencia)
        ]));
        $this->assertEquals(8, $movDest->cantidad);
        $this->assertEquals($variant->idproducto, $movDest->idproducto);

        // eliminamos
        $this->assertTrue($transferencia->delete());
        $this->assertTrue($warehouse2->delete());
        $this->assertTrue($warehouse->delete());
        $this->assertTrue($variant->getProducto()->delete());
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
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ];
        $this->assertFalse($stock->loadWhere($where));

        // comprobamos que no hay stock en el almacén de destino
        $stock2 = new Stock();
        $where2 = [
            Where::eq('codalmacen', $warehouse2->codalmacen),
            Where::eq('referencia', $product->referencia)
        ];
        $this->assertFalse($stock2->loadWhere($where2));

        // no debe existir ningún movimiento de stock para esta transferencia
        $movement = new MovimientoStock();
        $this->assertFalse($movement->loadWhere([
            Where::eq('docid', $transferencia->id()),
            Where::eq('docmodel', $transferencia->modelClassName())
        ]));

        // eliminamos
        $this->assertTrue($transferencia->delete());
        $this->assertTrue($warehouse2->delete());
        $this->assertTrue($warehouse->delete());
        $this->assertTrue($product->delete());
    }

    public function testCantTransferWithInsufficientStock(): void
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

        // añadimos stock al almacén de origen (5 unidades)
        $stock = new Stock();
        $stock->codalmacen = $warehouse->codalmacen;
        $stock->referencia = $product->referencia;
        $stock->idproducto = $product->idproducto;
        $stock->cantidad = 5;
        $this->assertTrue($stock->save());

        // creamos una transferencia de stock pidiendo más cantidad de la disponible
        $transferencia = new TransferenciaStock();
        $transferencia->codalmacenorigen = $warehouse->codalmacen;
        $transferencia->codalmacendestino = $warehouse2->codalmacen;
        $transferencia->observaciones = 'Transferencia stock insuficiente test';
        $this->assertTrue($transferencia->save());

        // añadimos el producto a la transferencia con cantidad mayor que la disponible
        $lineaTrans = $transferencia->addLine($product->referencia, $product->idproducto, 10);
        $this->assertTrue($lineaTrans->exists());

        // ejecutamos la transferencia, debe fallar
        $this->assertFalse($transferencia->transferStock());

        // comprobamos que la transferencia no está completada
        $transferencia->load($transferencia->idtrans);
        $this->assertFalse($transferencia->completed);

        // comprobamos que el stock del almacén de origen no se ha alterado
        $stock->load($stock->idstock);
        $this->assertEquals(5, $stock->cantidad);

        // comprobamos que no hay stock en el almacén de destino
        $stock2 = new Stock();
        $where2 = [
            Where::eq('codalmacen', $warehouse2->codalmacen),
            Where::eq('referencia', $product->referencia)
        ];
        $this->assertFalse($stock2->loadWhere($where2));

        // comprobamos que no se ha creado movimiento de stock para esta transferencia
        $movement = new MovimientoStock();
        $whereMov = [
            Where::eq('docid', $transferencia->id()),
            Where::eq('docmodel', $transferencia->modelClassName())
        ];
        $this->assertFalse($movement->loadWhere($whereMov));

        // eliminamos
        $this->assertTrue($transferencia->delete());
        $this->assertTrue($warehouse2->delete());
        $this->assertTrue($warehouse->delete());
        $this->assertTrue($product->delete());
    }

    public function testCantAddLineWithZeroOrNegativeQuantity(): void
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

        // añadimos stock al almacén de origen
        $stock = new Stock();
        $stock->codalmacen = $warehouse->codalmacen;
        $stock->referencia = $product->referencia;
        $stock->idproducto = $product->idproducto;
        $stock->cantidad = 50;
        $this->assertTrue($stock->save());

        // creamos una transferencia
        $transferencia = new TransferenciaStock();
        $transferencia->codalmacenorigen = $warehouse->codalmacen;
        $transferencia->codalmacendestino = $warehouse2->codalmacen;
        $transferencia->observaciones = 'Transferencia cantidad invalida test';
        $this->assertTrue($transferencia->save());

        // intentamos añadir una línea con cantidad 0
        $lineaCero = $transferencia->addLine($product->referencia, $product->idproducto, 0);
        $this->assertFalse($lineaCero->exists());

        // intentamos añadir una línea con cantidad negativa
        $lineaNeg = $transferencia->addLine($product->referencia, $product->idproducto, -5);
        $this->assertFalse($lineaNeg->exists());

        // comprobamos que la transferencia no tiene líneas
        $this->assertCount(0, $transferencia->getLines());

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
