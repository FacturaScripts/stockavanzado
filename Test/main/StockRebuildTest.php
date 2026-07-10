<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2025-2026 Carlos García Gómez <carlos@facturascripts.com>
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
use FacturaScripts\Dinamic\Lib\StockRebuildManager;
use FacturaScripts\Dinamic\Model\ConteoStock;
use FacturaScripts\Dinamic\Model\PedidoProveedor;
use FacturaScripts\Dinamic\Model\Stock;
use FacturaScripts\Dinamic\Model\Variante;
use FacturaScripts\Test\Traits\DefaultSettingsTrait;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use FacturaScripts\Test\Traits\RandomDataTrait;
use PHPUnit\Framework\TestCase;

final class StockRebuildTest extends TestCase
{
    use DefaultSettingsTrait;
    use LogErrorsTrait;
    use RandomDataTrait;

    public static function setUpBeforeClass(): void
    {
        self::setDefaultSettings();
        self::installAccountingPlan();
        self::removeTaxRegularization();
    }

    public function testCreate(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // creamos un conteo de stock
        $conteo = new ConteoStock();
        $conteo->codalmacen = $warehouse->codalmacen;
        $conteo->observaciones = 'Test';
        $this->assertTrue($conteo->save());

        // añadimos el producto al conteo de stock
        $linea = $conteo->addLine($product->referencia, $product->idproducto, 50);
        $this->assertTrue($linea->exists());

        // ejecutamos el conteo
        $this->assertTrue($conteo->updateStock());

        // obtenemos el stock del producto
        $stock = new Stock();
        $where = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ];
        $this->assertTrue($stock->loadWhere($where));

        // comprobamos que el stock es correcto
        $this->assertEquals(50, $stock->cantidad);

        // cambiamos la cantidad del stock
        $stock->cantidad = 100;
        $this->assertTrue($stock->save());

        // ejecutamos la reconstrucción del stock con base en sus movimientos
        $messages = [];
        StockRebuildManager::rebuild($product->idproducto, $messages);
        $this->assertEmpty($messages);

        // recargamos el stock
        $stock->load($stock->id());

        // comprobamos que el stock es correcto
        $this->assertEquals(50, $stock->cantidad);

        // eliminamos
        $this->assertTrue($conteo->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testRebuildSkipsOrphanReference(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // creamos un proveedor
        $proveedor = $this->getRandomSupplier();
        $this->assertTrue($proveedor->save());

        // creamos un pedido en ese almacén con una línea pendiente de recibir
        $pedido = new PedidoProveedor();
        $this->assertTrue($pedido->setSubject($proveedor));
        $this->assertTrue($pedido->setWarehouse($warehouse->codalmacen));
        $this->assertTrue($pedido->save());

        // añadimos una línea al pedido
        $linea = $pedido->getNewProductLine($product->referencia);
        $linea->cantidad = 10;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save());

        // renombramos la referencia de la variante;
        // la línea del pedido conserva la referencia antigua, que queda huérfana
        $oldRef = $product->referencia;
        $variante = new Variante();
        $this->assertTrue($variante->loadWhereEq('referencia', $oldRef));
        $variante->referencia = $oldRef . '-R';
        $this->assertTrue($variante->save());
        $this->assertEquals($oldRef, $pedido->getLines()[0]->referencia);

        // reconstruimos el stock; la referencia huérfana se debe omitir sin errores
        $messages = [];
        StockRebuildManager::rebuild($product->idproducto, $messages);
        $this->assertEmpty($messages);

        // comprobamos que no se ha creado stock para la referencia huérfana
        $stock = new Stock();
        $where = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $oldRef)
        ];
        $this->assertFalse($stock->loadWhere($where));

        // eliminamos
        $this->assertTrue($pedido->delete());
        $this->assertTrue($proveedor->delete());
        $this->assertTrue($proveedor->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    protected function tearDown(): void
    {
        $this->logErrors();
    }
}
