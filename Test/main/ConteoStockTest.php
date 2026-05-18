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

use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\ConteoStock;
use FacturaScripts\Dinamic\Model\LineaConteoStock;
use FacturaScripts\Dinamic\Model\MovimientoStock;
use FacturaScripts\Dinamic\Model\Stock;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use FacturaScripts\Test\Traits\RandomDataTrait;
use PHPUnit\Framework\TestCase;

final class ConteoStockTest extends TestCase
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

        // añadimos stock del producto 1 al almacén
        $stock1 = new Stock();
        $stock1->cantidad = 0;
        $stock1->codalmacen = $warehouse->codalmacen;
        $stock1->idproducto = $product1->idproducto;
        $stock1->referencia = $product1->referencia;
        $this->assertTrue($stock1->save());

        // añadimos stock del producto 2 al almacén
        $stock2 = new Stock();
        $stock2->cantidad = 5;
        $stock2->codalmacen = $warehouse->codalmacen;
        $stock2->idproducto = $product2->idproducto;
        $stock2->referencia = $product2->referencia;
        $this->assertTrue($stock2->save());

        // creamos un conteo de stock
        $conteo = new ConteoStock();
        $conteo->codalmacen = $warehouse->codalmacen;
        $conteo->observaciones = 'Test';
        $this->assertTrue($conteo->save());

        // añadimos el producto 1 al conteo de stock
        $linea1 = $conteo->addLine($product1->referencia, $product1->idproducto, 50);
        $this->assertTrue($linea1->exists());

        // añadimos el producto 2 al conteo de stock
        $linea2 = $conteo->addLine($product2->referencia, $product2->idproducto, 1);
        $this->assertTrue($linea2->exists());

        // comprobamos que el stock del producto 1 es 0
        $stock1->load($stock1->id());
        $this->assertEquals(0, $stock1->cantidad);

        // comprobamos que el stock del producto 2 es 5
        $stock2->load($stock2->id());
        $this->assertEquals(5, $stock2->cantidad);

        // ejecutamos el conteo
        $this->assertTrue($conteo->updateStock());

        // comprobamos que el conteo está completado
        $conteo->load($conteo->id());
        $this->assertTrue($conteo->completed);

        // si intento volver a ejecutarlo debe devolver true porque ya está completado
        $this->assertTrue($conteo->updateStock());

        // comprobamos que está el movimiento 1 de stock
        $movement1 = new MovimientoStock();
        $where = [
            Where::eq('codalmacen', $conteo->codalmacen),
            Where::eq('docid', $conteo->id()),
            Where::eq('docmodel', $conteo->modelClassName()),
            Where::eq('referencia', $linea1->referencia)
        ];
        $this->assertTrue($movement1->loadWhere($where));

        // comprobamos la cantidad del movimiento 1
        $this->assertEquals(0, $movement1->cantidad);

        // comprobamos el saldo del movimiento 1
        $this->assertEquals(50, $movement1->saldo);

        // comprobamos que está el movimiento 2 de stock
        $movement2 = new MovimientoStock();
        $where = [
            Where::eq('codalmacen', $conteo->codalmacen),
            Where::eq('docid', $conteo->id()),
            Where::eq('docmodel', $conteo->modelClassName()),
            Where::eq('referencia', $linea2->referencia)
        ];
        $this->assertTrue($movement2->loadWhere($where));

        // comprobamos la cantidad del movimiento 2
        $this->assertEquals(0, $movement2->cantidad);

        // comprobamos el saldo del movimiento 2
        $this->assertEquals(1, $movement2->saldo);

        // comprobamos que el stock del producto 1 es 50
        $stock1->load($stock1->id());
        $this->assertEquals(50, $stock1->cantidad);

        // comprobamos que el stock del producto 2 es 1
        $stock2->load($stock2->id());
        $this->assertEquals(1, $stock2->cantidad);

        // eliminamos el conteo
        $this->assertTrue($conteo->delete());

        // comprobamos que la línea ya no existe
        $this->assertFalse($linea1->exists());

        // comprobamos que los movimientos de stock ya no existe
        $this->assertFalse($movement1->exists());
        $this->assertFalse($movement2->exists());

        // comprobamos que el stock vuelve a 0
        $stock1->load($stock1->id());
        $this->assertEquals(0, $stock1->cantidad);

        // comprobamos que el stock vuelve a 5
        $stock2->load($stock2->id());
        $this->assertEquals(0, $stock2->cantidad);

        // eliminamos
        $this->assertTrue($stock1->delete());
        $this->assertTrue($product1->delete());
        $this->assertTrue($stock2->delete());
        $this->assertTrue($product2->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testAddLineConsolidatesSameReference(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // creamos un conteo
        $conteo = new ConteoStock();
        $conteo->codalmacen = $warehouse->codalmacen;
        $conteo->observaciones = 'Consolidacion lineas conteo test';
        $this->assertTrue($conteo->save());

        // primera llamada: 4 unidades
        $linea1 = $conteo->addLine($product->referencia, $product->idproducto, 4);
        $this->assertTrue($linea1->exists());
        $this->assertEquals(4, $linea1->cantidad);

        // segunda llamada con la misma referencia: 6 unidades adicionales
        $linea2 = $conteo->addLine($product->referencia, $product->idproducto, 6);
        $this->assertTrue($linea2->exists());

        // debe ser la misma línea consolidada con 4 + 6 = 10
        $this->assertEquals($linea1->idlinea, $linea2->idlinea);
        $this->assertEquals(10, $linea2->cantidad);

        // solo debe haber una línea en el conteo
        $this->assertCount(1, $conteo->getLines());

        // ejecutamos el conteo y el stock resultante debe ser 10
        $this->assertTrue($conteo->updateStock());

        $stock = new Stock();
        $this->assertTrue($stock->loadWhere([
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ]));
        $this->assertEquals(10, $stock->cantidad);

        // eliminamos
        $this->assertTrue($conteo->delete());
        $this->assertTrue($warehouse->delete());
        $this->assertTrue($product->delete());
    }

    public function testCantUpdateStockWithoutLines(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un conteo sin líneas
        $conteo = new ConteoStock();
        $conteo->codalmacen = $warehouse->codalmacen;
        $conteo->observaciones = 'Conteo sin lineas test';
        $this->assertTrue($conteo->save());

        // ejecutar el conteo debe fallar
        $this->assertFalse($conteo->updateStock());

        // el conteo no debe estar completado
        $conteo->load($conteo->idconteo);
        $this->assertFalse($conteo->completed);

        // eliminamos
        $this->assertTrue($conteo->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testCantCreateWithFutureDate(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un conteo con fechainicio futura
        $conteo = new ConteoStock();
        $conteo->codalmacen = $warehouse->codalmacen;
        $conteo->observaciones = 'Conteo fecha futura test';
        $conteo->fechainicio = date('d-m-Y', strtotime('+1 day'));
        $this->assertFalse($conteo->save());

        // con fechafin futura tampoco debe guardar
        $conteo->fechainicio = date('d-m-Y');
        $conteo->fechafin = date('d-m-Y H:i:s', strtotime('+1 day'));
        $this->assertFalse($conteo->save());

        // con fechas válidas sí debe guardar
        $conteo->fechafin = null;
        $this->assertTrue($conteo->save());

        // eliminamos
        $this->assertTrue($conteo->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testCantModifyDatesOrWarehouseOnCompletedCounting(): void
    {
        // creamos un almacén y un producto
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // creamos y completamos un conteo
        $conteo = new ConteoStock();
        $conteo->codalmacen = $warehouse->codalmacen;
        $conteo->observaciones = 'Conteo completado no modificable';
        $this->assertTrue($conteo->save());
        $linea = $conteo->addLine($product->referencia, $product->idproducto, 7);
        $this->assertTrue($linea->exists());
        $this->assertTrue($conteo->updateStock());

        // recargamos para tener el estado completado
        $conteo->load($conteo->idconteo);
        $this->assertTrue($conteo->completed);

        $originalFechainicio = $conteo->fechainicio;
        $originalFechafin = $conteo->fechafin;
        $originalAlmacen = $conteo->codalmacen;

        // no debe permitir modificar fechainicio
        $conteo->fechainicio = date('d-m-Y', strtotime('-1 day'));
        $this->assertFalse($conteo->save());
        $conteo->fechainicio = $originalFechainicio;

        // no debe permitir modificar fechafin
        $conteo->fechafin = date('d-m-Y H:i:s', strtotime('-1 hour'));
        $this->assertFalse($conteo->save());
        $conteo->fechafin = $originalFechafin;

        // no debe permitir cambiar el almacén
        $warehouse2 = $this->getRandomWarehouse();
        $this->assertTrue($warehouse2->save());
        $conteo->codalmacen = $warehouse2->codalmacen;
        $this->assertFalse($conteo->save());
        $conteo->codalmacen = $originalAlmacen;

        // observaciones sí debería poder modificarse
        $conteo->observaciones = 'Modificado';
        $this->assertTrue($conteo->save());

        // eliminamos
        $this->assertTrue($conteo->delete());
        $this->assertTrue($warehouse2->delete());
        $this->assertTrue($warehouse->delete());
        $this->assertTrue($product->delete());
    }

    public function testCantChangeWarehouseWithLines(): void
    {
        // creamos dos almacenes
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());
        $warehouse2 = $this->getRandomWarehouse();
        $this->assertTrue($warehouse2->save());

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // creamos un conteo y le añadimos una línea
        $conteo = new ConteoStock();
        $conteo->codalmacen = $warehouse->codalmacen;
        $conteo->observaciones = 'Conteo cambiar almacen test';
        $this->assertTrue($conteo->save());

        $linea = $conteo->addLine($product->referencia, $product->idproducto, 4);
        $this->assertTrue($linea->exists());

        // intentar cambiar el almacén debe fallar mientras tenga líneas
        $conteo->codalmacen = $warehouse2->codalmacen;
        $this->assertFalse($conteo->save());

        // restauramos
        $conteo->codalmacen = $warehouse->codalmacen;
        $this->assertTrue($conteo->save());

        // eliminamos
        $this->assertTrue($conteo->delete());
        $this->assertTrue($warehouse2->delete());
        $this->assertTrue($warehouse->delete());
        $this->assertTrue($product->delete());
    }

    public function testCantHaveTwoOpenCountingsSameWarehouse(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // primer conteo abierto
        $conteo1 = new ConteoStock();
        $conteo1->codalmacen = $warehouse->codalmacen;
        $conteo1->observaciones = 'Primer conteo abierto';
        $this->assertTrue($conteo1->save());

        // segundo conteo en el mismo almacén debe fallar mientras el primero esté abierto
        $conteo2 = new ConteoStock();
        $conteo2->codalmacen = $warehouse->codalmacen;
        $conteo2->observaciones = 'Segundo conteo abierto';
        $this->assertFalse($conteo2->save());

        // en otro almacén sí debe permitirse otro conteo abierto
        $warehouse2 = $this->getRandomWarehouse();
        $this->assertTrue($warehouse2->save());
        $conteoOtroAlmacen = new ConteoStock();
        $conteoOtroAlmacen->codalmacen = $warehouse2->codalmacen;
        $conteoOtroAlmacen->observaciones = 'Conteo otro almacen';
        $this->assertTrue($conteoOtroAlmacen->save());

        // completamos el primero y entonces sí debe permitirse uno nuevo en el almacén
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());
        $linea = $conteo1->addLine($product->referencia, $product->idproducto, 1);
        $this->assertTrue($linea->exists());
        $this->assertTrue($conteo1->updateStock());

        $conteo3 = new ConteoStock();
        $conteo3->codalmacen = $warehouse->codalmacen;
        $conteo3->observaciones = 'Tercer conteo tras completar';
        $this->assertTrue($conteo3->save());

        // eliminamos
        $this->assertTrue($conteo3->delete());
        $this->assertTrue($conteo1->delete());
        $this->assertTrue($conteoOtroAlmacen->delete());
        $this->assertTrue($warehouse2->delete());
        $this->assertTrue($warehouse->delete());
        $this->assertTrue($product->delete());
    }

    public function testCantCreateWithEndDateBeforeStartDate(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // intentamos crear un conteo con fechafin anterior a fechainicio
        $conteo = new ConteoStock();
        $conteo->codalmacen = $warehouse->codalmacen;
        $conteo->observaciones = 'Conteo fechafin anterior test';
        $conteo->fechainicio = date('d-m-Y', strtotime('-1 day'));
        $conteo->fechafin = date('d-m-Y H:i:s', strtotime('-3 days'));
        $this->assertFalse($conteo->save());

        // con fechafin posterior a fechainicio sí debe guardar
        $conteo->fechafin = date('d-m-Y H:i:s');
        $this->assertTrue($conteo->save());

        // eliminamos
        $this->assertTrue($conteo->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testCantCreateWithDateBeforeLastCounting(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // creamos y completamos un primer conteo (fechafin = ahora)
        $conteo1 = new ConteoStock();
        $conteo1->codalmacen = $warehouse->codalmacen;
        $conteo1->observaciones = 'Primer conteo fecha test';
        $this->assertTrue($conteo1->save());
        $linea = $conteo1->addLine($product->referencia, $product->idproducto, 10);
        $this->assertTrue($linea->exists());
        $this->assertTrue($conteo1->updateStock());

        // intentamos crear un nuevo conteo con fechainicio anterior al fechafin del primero
        $conteo2 = new ConteoStock();
        $conteo2->codalmacen = $warehouse->codalmacen;
        $conteo2->observaciones = 'Conteo fecha anterior test';
        $conteo2->fechainicio = date('d-m-Y', strtotime('-2 days'));
        $this->assertFalse($conteo2->save());

        // pero en otro almacén sí debe permitirse
        $warehouse2 = $this->getRandomWarehouse();
        $this->assertTrue($warehouse2->save());
        $conteo3 = new ConteoStock();
        $conteo3->codalmacen = $warehouse2->codalmacen;
        $conteo3->observaciones = 'Conteo otro almacen test';
        $conteo3->fechainicio = date('d-m-Y', strtotime('-2 days'));
        $this->assertTrue($conteo3->save());

        // eliminamos
        $this->assertTrue($conteo3->delete());
        $this->assertTrue($warehouse2->delete());
        $this->assertTrue($conteo1->delete());
        $this->assertTrue($warehouse->delete());
        $this->assertTrue($product->delete());
    }

    public function testCantAddLineWithMismatchedReferenceAndProduct(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos dos productos distintos
        $product1 = $this->getRandomProduct();
        $this->assertTrue($product1->save());
        $product2 = $this->getRandomProduct();
        $this->assertTrue($product2->save());

        // creamos un conteo
        $conteo = new ConteoStock();
        $conteo->codalmacen = $warehouse->codalmacen;
        $conteo->observaciones = 'Conteo referencia producto mismatch';
        $this->assertTrue($conteo->save());

        // referencia del producto1 con idproducto del producto2 debe fallar
        $linea = $conteo->addLine($product1->referencia, $product2->idproducto, 5);
        $this->assertFalse($linea->exists());
        $this->assertCount(0, $conteo->getLines());

        // referencia inexistente también debe fallar
        $lineaInexistente = $conteo->addLine('REF-INEXISTENTE', $product1->idproducto, 5);
        $this->assertFalse($lineaInexistente->exists());

        // con referencia e idproducto coherentes sí debe añadir
        $lineaOk = $conteo->addLine($product1->referencia, $product1->idproducto, 5);
        $this->assertTrue($lineaOk->exists());

        // eliminamos
        $this->assertTrue($conteo->delete());
        $this->assertTrue($warehouse->delete());
        $this->assertTrue($product1->delete());
        $this->assertTrue($product2->delete());
    }

    public function testCantAddLineWithNegativeQuantity(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // creamos un conteo
        $conteo = new ConteoStock();
        $conteo->codalmacen = $warehouse->codalmacen;
        $conteo->observaciones = 'Conteo cantidad negativa test';
        $this->assertTrue($conteo->save());

        // intentamos añadir una línea con cantidad negativa
        $lineaNeg = $conteo->addLine($product->referencia, $product->idproducto, -3);
        $this->assertFalse($lineaNeg->exists());

        // una línea con cantidad 0 sí es válida (he contado y hay 0)
        $lineaCero = $conteo->addLine($product->referencia, $product->idproducto, 0);
        $this->assertTrue($lineaCero->exists());
        $this->assertEquals(0, $lineaCero->cantidad);

        // solo debe haber una línea
        $this->assertCount(1, $conteo->getLines());

        // eliminamos
        $this->assertTrue($conteo->delete());
        $this->assertTrue($warehouse->delete());
        $this->assertTrue($product->delete());
    }

    public function testCantAddLineForNostockProduct(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto que no gestiona stock
        $product = $this->getRandomProduct();
        $product->nostock = true;
        $this->assertTrue($product->save());

        // creamos un conteo
        $conteo = new ConteoStock();
        $conteo->codalmacen = $warehouse->codalmacen;
        $conteo->observaciones = 'Conteo producto nostock test';
        $this->assertTrue($conteo->save());

        // intentamos añadir el producto nostock al conteo, debe fallar
        $linea = $conteo->addLine($product->referencia, $product->idproducto, 5);
        $this->assertFalse($linea->exists());

        // el conteo no debe tener líneas
        $this->assertCount(0, $conteo->getLines());

        // eliminamos
        $this->assertTrue($conteo->delete());
        $this->assertTrue($warehouse->delete());
        $this->assertTrue($product->delete());
    }

    public function testCantUpdateStockIfProductTurnsNostockAfterAddLine(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto que sí gestiona stock
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // creamos un conteo y añadimos una línea
        $conteo = new ConteoStock();
        $conteo->codalmacen = $warehouse->codalmacen;
        $conteo->observaciones = 'Conteo con cambio nostock';
        $this->assertTrue($conteo->save());

        $linea = $conteo->addLine($product->referencia, $product->idproducto, 10);
        $this->assertTrue($linea->exists());

        // ahora marcamos el producto como nostock
        $product->nostock = true;
        $this->assertTrue($product->save());

        // ejecutar el conteo debe fallar
        $this->assertFalse($conteo->updateStock());

        // el conteo no debe estar completado
        $conteo->load($conteo->idconteo);
        $this->assertFalse($conteo->completed);

        // no debe existir movimiento de stock para este conteo
        $movement = new MovimientoStock();
        $this->assertFalse($movement->loadWhere([
            Where::eq('docid', $conteo->id()),
            Where::eq('docmodel', $conteo->modelClassName())
        ]));

        // eliminamos
        $this->assertTrue($conteo->delete());
        $this->assertTrue($warehouse->delete());
        $this->assertTrue($product->delete());
    }

    public function testCantModifyCompletedCounting(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos dos productos
        $product1 = $this->getRandomProduct();
        $this->assertTrue($product1->save());
        $product2 = $this->getRandomProduct();
        $this->assertTrue($product2->save());

        // creamos un conteo con producto1 y lo ejecutamos
        $conteo = new ConteoStock();
        $conteo->codalmacen = $warehouse->codalmacen;
        $conteo->observaciones = 'Conteo completado test';
        $this->assertTrue($conteo->save());

        $linea = $conteo->addLine($product1->referencia, $product1->idproducto, 5);
        $this->assertTrue($linea->exists());
        $this->assertTrue($conteo->updateStock());

        // recargamos el conteo y confirmamos que está completado
        $conteo->load($conteo->idconteo);
        $this->assertTrue($conteo->completed);

        // 1) addLine de un producto nuevo debe fallar
        $nuevaLinea = $conteo->addLine($product2->referencia, $product2->idproducto, 3);
        $this->assertFalse($nuevaLinea->exists());
        $this->assertCount(1, $conteo->getLines());

        // 2) addLine sobre la misma referencia tampoco debe modificar la línea existente
        $linea->load($linea->idlinea);
        $cantidadOriginal = $linea->cantidad;
        $reintento = $conteo->addLine($product1->referencia, $product1->idproducto, 7);
        $this->assertFalse($reintento->exists());
        $linea->load($linea->idlinea);
        $this->assertEquals($cantidadOriginal, $linea->cantidad);

        // 3) guardar directamente una modificación sobre la línea existente debe fallar
        $linea->cantidad = 99;
        $this->assertFalse($linea->save());
        $linea->load($linea->idlinea);
        $this->assertEquals($cantidadOriginal, $linea->cantidad);

        // 4) eliminar directamente la línea de un conteo completado debe fallar
        $this->assertFalse($linea->delete());
        $this->assertTrue($linea->exists());

        // borrar el conteo entero sí debe seguir funcionando
        $this->assertTrue($conteo->delete());
        $this->assertFalse($linea->exists());

        // eliminamos
        $this->assertTrue($warehouse->delete());
        $this->assertTrue($product1->delete());
        $this->assertTrue($product2->delete());
    }

    public function testUpdateStockConsolidatesMultipleLinesSameReference(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // creamos un conteo
        $conteo = new ConteoStock();
        $conteo->codalmacen = $warehouse->codalmacen;
        $conteo->observaciones = 'Conteo multiples lineas misma ref test';
        $this->assertTrue($conteo->save());

        // guardamos dos líneas separadas para la misma referencia (sin pasar por addLine)
        $linea1 = new LineaConteoStock();
        $linea1->idconteo = $conteo->idconteo;
        $linea1->referencia = $product->referencia;
        $linea1->idproducto = $product->idproducto;
        $linea1->cantidad = 4;
        $this->assertTrue($linea1->save());

        $linea2 = new LineaConteoStock();
        $linea2->idconteo = $conteo->idconteo;
        $linea2->referencia = $product->referencia;
        $linea2->idproducto = $product->idproducto;
        $linea2->cantidad = 6;
        $this->assertTrue($linea2->save());

        // el conteo debe tener las dos líneas separadas
        $this->assertCount(2, $conteo->getLines());

        // ejecutamos el conteo
        $this->assertTrue($conteo->updateStock());

        // el stock resultante debe ser la suma consolidada (4 + 6 = 10)
        $stock = new Stock();
        $this->assertTrue($stock->loadWhere([
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ]));
        $this->assertEquals(10, $stock->cantidad);

        // eliminamos
        $this->assertTrue($conteo->delete());
        $this->assertTrue($warehouse->delete());
        $this->assertTrue($product->delete());
    }

    public function testCountVariant(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos una variante (con su propio producto detrás)
        $variant = $this->getRandomVariant();
        $this->assertTrue($variant->save());

        // creamos un conteo
        $conteo = new ConteoStock();
        $conteo->codalmacen = $warehouse->codalmacen;
        $conteo->observaciones = 'Conteo de variante test';
        $this->assertTrue($conteo->save());

        // añadimos la variante al conteo sin pasar idproducto (debe resolverse desde la variante)
        $linea = $conteo->addLine($variant->referencia, 0, 7);
        $this->assertTrue($linea->exists());
        $this->assertEquals($variant->idproducto, $linea->idproducto);

        // ejecutamos el conteo
        $this->assertTrue($conteo->updateStock());

        // el stock del almacén debe ser 7 con el idproducto correcto
        $stock = new Stock();
        $this->assertTrue($stock->loadWhere([
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $variant->referencia)
        ]));
        $this->assertEquals(7, $stock->cantidad);
        $this->assertEquals($variant->idproducto, $stock->idproducto);

        // debe existir un movimiento de stock para el conteo con el saldo igual al stock
        $movement = new MovimientoStock();
        $this->assertTrue($movement->loadWhere([
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('docid', $conteo->id()),
            Where::eq('docmodel', $conteo->modelClassName()),
            Where::eq('referencia', $variant->referencia)
        ]));
        $this->assertEquals(0, $movement->cantidad);
        $this->assertEquals(7, $movement->saldo);
        $this->assertEquals($variant->idproducto, $movement->idproducto);

        // eliminamos
        $this->assertTrue($conteo->delete());
        $this->assertTrue($warehouse->delete());
        $this->assertTrue($variant->getProducto()->delete());
    }

    public function testCantCreateWithoutWarehouse(): void
    {
        // intentamos crear un conteo sin almacén
        $conteo = new ConteoStock();
        $conteo->codalmacen = null;
        $conteo->observaciones = 'Test';
        $this->assertFalse($conteo->save());
    }

    public function testCantCreateOnInvalidWarehouse(): void
    {
        // intentamos crear un conteo en un almacén inexistente
        $conteo = new ConteoStock();
        $conteo->codalmacen = 'invalid';
        $conteo->observaciones = 'Test';
        $this->assertFalse($conteo->save());
    }

    public function testDeleteBeforeComplete(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // creamos el stock del producto
        $stock = new Stock();
        $stock->cantidad = 3;
        $stock->codalmacen = $warehouse->codalmacen;
        $stock->idproducto = $product->idproducto;
        $stock->referencia = $product->referencia;
        $this->assertTrue($stock->save());

        // creamos un conteo de stock
        $conteo = new ConteoStock();
        $conteo->codalmacen = $warehouse->codalmacen;
        $conteo->observaciones = 'Test';
        $this->assertTrue($conteo->save());

        // añadimos el producto al conteo de stock
        $linea = $conteo->addLine($product->referencia, $product->idproducto, 50);
        $this->assertTrue($linea->exists());

        // comprobamos que el stock del producto es 3
        $stock->load($stock->id());
        $this->assertEquals(3, $stock->cantidad);

        // eliminamos el conteo
        $this->assertTrue($conteo->delete());

        // comprobamos que el stock del producto sigue siendo 3
        $stock->load($stock->idstock);
        $this->assertEquals(3, $stock->cantidad);

        // eliminamos
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testEscapeHtml(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un conteo con html en las observaciones
        $conteo = new ConteoStock();
        $conteo->codalmacen = $warehouse->codalmacen;
        $conteo->observaciones = '<script>alert("XSS")</script>';
        $this->assertTrue($conteo->save());

        // comprobamos que se ha escapado el html
        $this->assertEquals('&lt;script&gt;alert(&quot;XSS&quot;)&lt;/script&gt;', $conteo->observaciones);

        // eliminamos
        $this->assertTrue($conteo->delete());
        $this->assertTrue($warehouse->delete());
    }

    protected function tearDown(): void
    {
        $this->logErrors();
    }
}
