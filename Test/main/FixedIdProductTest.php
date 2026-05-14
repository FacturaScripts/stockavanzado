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

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Template\CronJobClass;
use FacturaScripts\Dinamic\Lib\StockMovementManager;
use FacturaScripts\Dinamic\Model\ConteoStock;
use FacturaScripts\Dinamic\Model\MovimientoStock;
use FacturaScripts\Plugins\StockAvanzado\CronJob\FixedIdProduct;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use FacturaScripts\Test\Traits\RandomDataTrait;
use PHPUnit\Framework\TestCase;

final class FixedIdProductTest extends TestCase
{
    use LogErrorsTrait;
    use RandomDataTrait;

    public function testFixesMovimientoStockIdProducto(): void
    {
        $db = new DataBase();
        $db->connect();

        // creamos almacén y producto (el producto genera su variante automáticamente)
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // segundo producto, solo para tener un idproducto válido distinto que respete el FK
        $otherProduct = $this->getRandomProduct();
        $this->assertTrue($otherProduct->save());

        // creamos un conteo para generar un movimiento de stock real
        $conteo = new ConteoStock();
        $conteo->codalmacen = $warehouse->codalmacen;
        $conteo->observaciones = 'Test FixedIdProduct';
        $this->assertTrue($conteo->save());

        $linea = $conteo->addLine($product->referencia, $product->idproducto, 10);
        $this->assertTrue($linea->exists());
        $this->assertTrue($conteo->updateStock());

        // cargamos el movimiento creado
        $movement = new MovimientoStock();
        $where = [
            \FacturaScripts\Core\Where::eq('codalmacen', $conteo->codalmacen),
            \FacturaScripts\Core\Where::eq('docid', $conteo->id()),
            \FacturaScripts\Core\Where::eq('docmodel', $conteo->modelClassName()),
            \FacturaScripts\Core\Where::eq('referencia', $linea->referencia)
        ];
        $this->assertTrue($movement->loadWhere($where));
        $correctIdProduct = (int)$product->idproducto;

        // desincronizamos el idproducto en BD (caso "valor incorrecto" pero válido por FK)
        $this->assertTrue($db->exec('UPDATE stocks_movimientos SET idproducto = ' . (int)$otherProduct->idproducto
            . ' WHERE id = ' . (int)$movement->id . ';'));

        // ejecutamos el cron en modo log para no imprimir nada
        FixedIdProduct::echoMode(CronJobClass::ECHO_MODE_LOG);
        FixedIdProduct::run();

        // recargamos y comprobamos que se ha corregido
        $this->assertTrue($movement->loadFromCode($movement->id));
        $this->assertEquals($correctIdProduct, (int)$movement->idproducto);

        // limpiamos
        $this->assertTrue($conteo->delete());
        StockMovementManager::rebuild($product->idproducto);
        $this->assertTrue($product->delete());
        $this->assertTrue($otherProduct->delete());
        $this->assertTrue($warehouse->delete());
    }

    protected function tearDown(): void
    {
        $this->logErrors();
    }
}
