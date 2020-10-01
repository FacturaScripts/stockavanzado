<?php
/**
 * This file is part of Inventory plugin for FacturaScripts
 * Copyright (C) 2020 Juan José Prieto Dzul <juanjoseprieto88@gmail.com>
 */
namespace FacturaScripts\Plugins\ConteoInventario\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Dinamic\Model\CodeModel;
use FacturaScripts\Dinamic\Model\Producto;
use FacturaScripts\Dinamic\Model\Variante;
use FacturaScripts\Dinamic\Model\ConteoStock;
use FacturaScripts\Plugins\ConteoInventario\Model\LineaConteoStock;
use function json_encode;

/**
 * Controller to process Point of Sale Operations
 *
 * @author Juan José Prieto Dzul <juanjoseprieto88@gmail.com>
 */
class CapturaInventario extends Controller
{
    public $variante;
    /**
     * @param Response $response
     * @param User $user
     * @param ControllerPermissions $permissions
     */
    public function privateCore(&$response, $user, $permissions)
    {
        /** @noinspection PhpParamsInspection */
        parent::privateCore($response, $user, $permissions);
        $this->setTemplate(false);

        if (false === $this->isSessionOpen()) {
            $this->toolBox()->log()->warning('No se ha abierto ninguna sesion de conteo.');
        }

        // Get any operations that have to be performed
        $action = $this->request->request->get('action', '');

        /** Run operations before load all data and stop exceution if not nedeed*/
        if ($this->execPreviusAction($action) === false) return;

        /** Set view template*/
        $template = 'InventarioMovil';
        $this->setTemplate($template);
    }

    /**
     * Returns basic page attributes
     *
     * @return array
     */
    public function getPageData()
    {
        $pagedata = parent::getPageData();
        $pagedata['menu'] = 'warehouse';
        $pagedata['title'] = 'quick-count';
        $pagedata['icon'] = 'fas fa-barcode';
        $pagedata['showonmenu'] = false;

        return $pagedata;
    }

    public function getFamilias()
    {
        $result = CodeModel::all('familias', 'codfamilia', 'descripcion', true);
        return $result;
    }

    public function getSessionRecords($referencia)
    {
        $session = new ConteoStock();
        $session->loadFromUser($this->user->nick);

        return $session->getCountRecords($referencia);
    }

    /**
     * @param string $action
     * @return bool
     */
    private function execPreviusAction(string $action)
    {
        switch ($action) {
            case 'query-search':
                $this->searchbyText();
                return false;

            case 'edit-product':
                $this->editProduct();
                return true;

            case 'load-product':
                $this->loadProduct();
                return false;

            case 'load-product-count':
                $this->loadProductCount();
                return false;

            case 'save-product-count':
                $this->saveProductCount();
                return true;

            case 'new-product':
                $this->addProduct();
                return true;

            default:
                return true;
        }
    }

    private function searchbyText()
    {
        $query = $this->request->request->get('query');

        $query = str_replace(" ", "%", $query);
        $result = (new Variante())->codeModelSearch($query, 'referencia');

        $this->response->setContent(json_encode($result));
    }

    private function loadProduct()
    {
        $this->setTemplate('InventarioMovil/Modal/EditProductModal');
        $referencia = $this->request->request->get('code');
        $where = [new DataBaseWhere('referencia', $referencia)];

        $this->variante = new Variante();
        $this->variante->loadFromCode('', $where);
    }

    private function loadProductCount()
    {
        $this->setTemplate('InventarioMovil/Modal/CountProductModal');
        $referencia = $this->request->request->get('code');
        $where = [new DataBaseWhere('referencia', $referencia)];

        $this->variante = new Variante();
        $this->variante->loadFromCode('', $where);
    }

    private function editProduct()
    {
        $this->setTemplate('InventarioMovil/Modal/EditProductModal');
        $data = $this->request->request->all();

        $this->variante = new Variante();
        $this->variante->loadFromData($data, ['action']);

        if ($this->variante->save()) {
            $this->toolBox()->log()->notice('Producto guardado correctamente');
        }
    }

    private function addProduct()
    {
        $data = $this->request->request->all();

        $producto = new Producto();
        $producto->loadFromData($data, ['action']);

        if ($producto->save()) {
            $this->toolBox()->log()->notice('Producto guardado correctamente');

            $this->variante = new Variante();
            $where = [new DataBaseWhere('referencia', $producto->referencia)];

            $this->variante->loadFromCode('', $where);
            $this->variante->codbarras = $data['codbarras'];
            $this->variante->save();
        }
    }

    private function isSessionOpen()
    {
        return (new ConteoStock())->loadFromUser($this->user->nick);
    }

    private function saveProductCount()
    {
        $session = new ConteoStock();

        if (false === $session->loadFromUser($this->user->nick))
            return;

        $cantidad = $this->request->request->get('cantidad');
        $codigo = $this->request->request->get('idvariante');
        $variante = new Variante();

        if (isset($codigo) && $variante->loadFromCode($codigo)) {
            $registro = new LineaConteoStock();
            $registro->cantidad = $cantidad;
            $registro->referencia = $variante->referencia;
            $registro->idconteo = $session->idconteo;
            $registro->idproducto = $variante->idproducto;

            $registro->save();
        }
    }
}