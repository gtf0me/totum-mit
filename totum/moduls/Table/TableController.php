<?php

namespace totum\moduls\Table;

use Psr\Http\Message\ServerRequestInterface;
use totum\common\controllers\interfaceController;
use totum\common\controllers\WithAuthTrait;
use totum\common\criticalErrorException;
use totum\common\Crypt;
use totum\common\Cycle;
use totum\common\errorException;
use totum\common\Auth;
use totum\common\logs\CalculateLog;
use totum\common\WithPathMessTrait;
use totum\common\sql\SqlException;
use totum\common\tableSaveException;
use totum\common\Totum;
use totum\config\Conf;
use totum\models\Table;
use totum\models\Tree;
use totum\tableTypes\aTable;

class TableController extends interfaceController
{
    use WithAuthTrait;

    /**
     * @var aTable
     */
    protected $Table;
    protected $onlyRead;
    protected $branchId;
    /**
     * @var Cycle
     */
    protected $Cycle;
    private $logTypes;
    /**
     * @var mixed
     */
    private $anchorId;
    /**
     * @var string|string[]
     */
    protected $tableUri;
    /**
     * @var CalculateLog
     */
    protected $CalculateLog;
    protected $totumTries = 0;

    public function __construct(Conf $Config, $totumPrefix = '')
    {
        $this->Config = $Config;
        parent::__construct($Config, $totumPrefix);
        static::$contentTemplate = $this->folder . '/__Table.php';
    }

    public function actionMain(ServerRequestInterface $request)
    {
        foreach (Table::init($this->Config)->getAll(
            ['id' => $this->User->getFavoriteTables()],
            'id, top, title, type',
            'sort'
        ) as $t) {
            $tree[] = [
                'id' => 'table' . $t['id']
                , 'href' => '/Table/' . $t['top'] . '/' . $t['id']
                , 'text' => $t['title']
                , 'type' => 'table_' . $t['type']
                , 'parent' => '#'
            ];
        }
        $this->__addAnswerVar('treeData', $tree);
        $this->__addAnswerVar('ModulePath', '');
        $this->__addAnswerVar('html', $this->Config->getSettings('main_page'));
    }

    public function actionAjaxActions(ServerRequestInterface $request)
    {
        $this->checkTableByUri($request);
        $this->Totum->transactionStart();

        try {
            if (!($method = $request->getParsedBody()['method'] ?? '')) {
                throw new errorException('Ошибка. Не указан метод');
            }
            $Actions = $this->getTableActions($request, $method);

            /*TODO remove test
            if ($this->totumTries < 1) {
                $this->Totum->getConfig()->getSql(true)->exec('update tables set updated=\'"' . rand(
                        0,
                        111
                    ) . '"\' where id='.$this->Table->getTableRow()['id']);
            }*/

            /** @var string $method */
            $result = $Actions->$method();


            if ($links = $this->Totum->getInterfaceLinks()) {
                $result['links'] = $links;
            }
            if ($panels = $this->Totum->getPanelLinks()) {
                $result['panels'] = $panels;
            }
            if ($links = $this->Totum->getInterfaceDatas()) {
                $result['interfaceDatas'] = $links;
            }
            $this->Totum->transactionCommit();
        } catch (\Exception $exception) {
            $result = ['error' => $exception->getMessage() . ($this->User->isCreator() ? "<br/>" . $exception->getPathMess() : '')];
        }

        if ($this->User->isCreator() && $this->CalculateLog && ($types = $this->Totum->getCalculateLog()->getTypes())) {
            $this->CalculateLog->addParam('result', 'done');
            $result['LOGS'] = $this->CalculateLog->getLogsByElements($this->Table->getTableRow()['id']);
            //$result['TREELOGS'] = $this->CalculateLog->getLodTree();
            $result['FullLOGS'] = [$this->CalculateLog->getLogsForjsTree()];
            if (in_array('flds', $types)) {
                $result['FieldLOGS'] = $this->CalculateLog->getFieldLogs();
            }
        }

        return $result;
    }


    protected function setTreeData()
    {
        $this->__addAnswerVar('Branch', $this->branchId);
        $this->__addAnswerVar('ModulePath', $this->modulPath);
        $tree = [];
        $branchIds = [];

        if ($this->User->isCreator()) {
            $branchesArray = Tree::init($this->Config)->getBranchesForCreator($this->branchId);
        } else {
            $branchesArray = Tree::init($this->Config)->getBranchesByTables(
                $this->branchId,
                array_keys($this->User->getTreeTables()),
                $this->User->getRoles()
            );
        }
        foreach ($branchesArray as $t) {
            if (!$t['parent_id']) {
                if ($t['id'] == $this->branchId) {
                    $this->__addAnswerVar('title', $t['title']);
                    $this->__addAnswerVar('BranchTitle', $t['title']);
                }
                if ($t['top'] != $this->branchId) {
                    continue;
                }
            }

            $tree[] =
                ($t['type'] == 'link' ? ['link' => $t['link']] : []) + [
                    'id' => 'tree' . $t['id']
                    , 'text' => $t['title']
                    , 'type' => $t['type'] ? ($t['type'] == 'anchor' ? "link" : $t['type']) : 'folder'
                    , 'link' => $t['type'] == 'anchor' ? ('/Table/' . $t['id'] . '/') : null
                    , 'parent' => ($parent = (!$t['parent_id'] ? '#' : 'tree' . $t['parent_id']))
                    , 'state' => [
                        'selected' => $t['type'] == 'anchor' ? ($this->anchorId == $t['id']) : false
                    ]
                ]
                + (
                $t['icon'] ? ['icon' => 'fa fa-' . $t['icon']] : []
                );
            if ($t['type'] != "link") {
                $branchIds[] = $t['id'];
            }
        }
        if ($branchIds) {
            foreach (Table::init($this->Config)->getAll(
                ['tree_node_id' => ($branchIds), 'id' => array_keys($this->User->getTreeTables())],
                'id, title, type, tree_node_id',
                '(sort->>\'v\')::numeric'
            ) as $t) {
                $tree[] = [
                    'id' => 'table' . $t['id']
                    , 'href' => $t['id']
                    , 'text' => $t['title']
                    , 'type' => 'table_' . $t['type']
                    , 'parent' => 'tree' . $t['tree_node_id']
                    , 'state' => [
                        'selected' => (!$this->anchorId && $this->Table && $this->Table->getTableRow()['id'] == $t['id'] ? true : false)
                    ]
                ];
            }
        }

        if ($this->Table && ($this->Table->getTableRow()['type'] == 'calcs') && $this->Cycle) {
            $idHref = 'Cycle' . $this->Cycle->getId();
            $isOneTable = false;
            if ($this->User->isOneCycleTable($this->Cycle->getCyclesTable()->getTableRow()) && $this->Cycle->getCyclesTable()->getUserCyclesCount() === 1) {
                $idHref = 'table' . $this->Table->getTableRow()['tree_node_id'];
                $isOneTable = true;
            } else {
                $cycleRow = [
                    'id' => $idHref
                    , 'href' => '#'
                    , 'text' => $this->Cycle->getRowName()
                    , 'type' => 'cycle_name'
                    , 'parent' => ($this->anchorId ? 'tree' . $this->anchorId : 'table' . $this->Table->getTableRow()['tree_node_id'])
                    , 'state' => [
                        'selected' => false
                    ]
                ];
                $tree[] = &$cycleRow;
            }


            foreach ($this->Cycle->getListTables() as $i => $tId) {
                if (array_key_exists($tId, $this->User->getTreeTables())) {
                    if ($tableRow = $this->Totum->getTableRow($tId)) {
                        $tree[] = [
                            'id' => 'table' . $tId
                            , 'href' => $this->Table->getTableRow()['tree_node_id'] . '/' . $this->Cycle->getId() . '/' . $tId
                            , 'text' => $tableRow['title']
                            , 'type' => 'table_calcs'
                            , 'parent' => $idHref
                            , 'isCycleTable' => true
                            , 'isOneUserCycle' => $isOneTable
                            , 'state' => [
                                'selected' => ($this->Table->getTableRow()['id'] == $tId ? true : false)
                            ]
                        ];
                        if ($this->anchorId) {
                            unset($tree[count($tree) - 1]['href']);
                            $tree[count($tree) - 1]['link'] = '/Table/' . $this->anchorId . '/' . $this->Cycle->getId() . '/' . $tId;
                        }
                        if ($i === 0 && !empty($cycleRow)) {
                            if ($this->anchorId) {
                                $cycleRow['link'] = '/Table/' . $this->anchorId . '/' . $this->Cycle->getId() . '/' . $tId;
                            } else {
                                $cycleRow['href'] = $this->Table->getTableRow()['tree_node_id'] . '/' . $this->Cycle->getId() . '/' . $tId;
                            }
                        }
                    }
                }
            }
        }


        foreach ($tree as $i => $_t) {
            if ($_t['id'] === 'tree' . $this->branchId) {
                unset($tree[$i]);
            } elseif ($_t['parent'] === 'tree' . $this->branchId) {
                $tree[$i]['parent'] = '#';
            }
        }
        $tree = array_values($tree);


        $this->__addAnswerVar('treeData', $tree);
    }

    protected function outputHtmlTemplate()
    {
        try {
            if ($this->User) {
                $this->__addAnswerVar('isCreatorView', $this->User->isCreator());
                $this->__addAnswerVar('UserName', $this->User->getVar('fio'));
                if ($this->User->isCreator() || Auth::isCreatorOnShadow()) {
                    $this->__addAnswerVar(
                        'reUsers',
                        $this->Config->getModel('users')->getFieldIndexedById(
                            'fio',
                            ['is_del' => false, 'interface' => 'web', 'on_off' => 'true', '!login' => ['service', 'cron']]
                        )
                    );

                    $this->__addAnswerVar('isCreatorNotItself', Auth::isCreatorNotItself());
                }
            }
            $this->__addAnswerVar(
                'schema_name',
                $this->Config->getSettings('totum_name') ?? $this->Config->getSchema()
            );

            $this->__addAnswerVar('notification_period', $this->Config->getSettings('notifications_period') ?? 0);
            $this->__addAnswerVar('topBranches', $this->getTopBranches());
            $this->__addAnswerVar('totumFooter', $this->Config->getTotumFooter());

            if (empty($this->answerVars['treeData'])) {
                $this->setTreeData();
            }

            if (isset($this->Table)) {
                $this->__addAnswerVar('title', $this->Table->getTableRow()['title']);
            }
        } catch (SqlException $e) {
            $this->Config->getLogger('sql')->error($e->getMessage(), $e->getTrace());
            $this->__addAnswerVar('error', "Ошибка базы данных");
        }
        parent::outputHtmlTemplate();
    }

    /**
     * @return array
     */
    protected function getTopBranches()
    {
        if ($this->User->isCreator()) {
            $topBranches = Tree::init($this->Config)->getBranchesForCreator(null);
        } else {
            $topBranches = Tree::init($this->Config)->getBranchesByTables(
                null,
                array_keys($this->User->getTreeTables()),
                $this->User->getRoles()
            );
        }
        foreach ($topBranches as &$branch) {
            $href = '/Table/' . $branch['id'] . '/';

            if (!empty($branch['default_table']) && $this->User->isTableInAccess($branch['default_table'])) {
                $href .= $branch['default_table'] . '/';
            }
            $branch['href'] = $href;
            if (is_a($this, TableController::class) && !empty($this->branchId) && $branch['id'] == $this->branchId) {
                $branch['active'] = true;
            }
        }
        unset($branch);
        return $topBranches;
    }

    /**
     * Check action from path, run, output
     *
     * @param ServerRequestInterface $request
     * @param bool $output
     */
    public function doIt(ServerRequestInterface $request, bool $output)
    {
        $requestUri = preg_replace('/\?.*/', '', $request->getUri()->getPath());
        $requestTable = substr($requestUri, strlen($this->modulPath));

        $post = ($request->getParsedBody());

        if ($post['ajax'] ?? null) {
            $this->isAjax = true;
        }
        if ($requestTable || $this->isAjax) {
            if (!$this->isAjax) {
                $action = 'Table';
            } else {
                $action = 'Actions';
            }
            $this->tableUri = $requestTable;
        } else {
            $action = 'Main';
        }
        try {
            try {
                if ($this->isAjax) {
                    $action = 'Ajax' . $action;
                }
                $this->__run($action, $request);
            } catch (tableSaveException $exception) {
                if (++$this->totumTries < 5) {
                    $this->Config = $this->Config->getClearConf();
                    $this->answerVars = [];
                    $this->doIt($request, false);
                } else {
                    throw new \Exception('Ошибка одновременного доступа к таблице');
                }
            }
        } catch (\Exception $e) {
            if (!$this->isAjax) {
                static::$contentTemplate = $this->Config::getTemplatesDir() . '/__error.php';
            }
            $message = $e->getMessage();
            if ($this->User && $this->User->isCreator() && key_exists(
                    WithPathMessTrait::class,
                    class_uses(get_class($e))
                )) {
                $message .= "<br/>" . $e->getPathMess();
            }
            $this->__addAnswerVar('error', $message);
        }
        if ($output) {
            $this->output($action);
        }
    }

    public function __actionRun($action, ServerRequestInterface $request)
    {
        $this->Totum = new Totum($this->Config, $this->User);
        $this->Totum->setCalcsTypesLog(json_decode($request->getCookieParams()['pcTableLogs'] ?? '[]', true));

        parent::__actionRun($action, $request);
    }


    public function actionTable(ServerRequestInterface $request)
    {
        $this->checkTableByUri($request);

        if (!$this->Table) {
            return;
        }
        try {
            /*Для таблиц циклов с одним циклом на пользователя*/
            if ($this->Table->getUser()->isOneCycleTable($this->Table->getTableRow())) {
                $cyclesCount = $this->Table->getUserCyclesCount();
                if ($cyclesCount === 0) {
                    $this->Table->reCalculateFromOvers(['add' => []]);
                    $cyclesCount = 1;
                }
                if ($cyclesCount === 1) {
                    $Cycle = $this->Totum->getCycle(
                        $this->Table->getUserCycles($this->Table->getUser()->getId())[0],
                        $this->Table->getTableRow()['id']
                    );
                    $calcsTablesIDs = $Cycle->getTableIds();
                    if (!empty($calcsTablesIDs)) {
                        foreach ($calcsTablesIDs as $tableId) {
                            if ($this->Table->getUser()->isTableInAccess($tableId)) {
                                $this->location('/Table/' . $this->Table->getTableRow()['top'] . '/' . $this->Table->getTableRow()['id'] . '/' . $Cycle->getId() . '/' . $tableId);
                                die;
                            }
                        }
                    }
                }
            }
        } catch (criticalErrorException $e) {
            $error = 'Ошибка ' . $e->getMessage();
        }
        if (empty($error)) {
            try {
                $Actions = $this->getTableActions($request, "getFullTableData");
                $result = $Actions->getFullTableData(true);
            } catch (criticalErrorException $exception) {
                $this->clearTotum($request);
                $Actions = $this->getTableActions($request, "getFullTableData");
                $error = $exception->getMessage();
                $result = $Actions->getFullTableData(false);
            }
        }

        $result['isCreatorView'] = $this->User->isCreator();
        $result['checkIsUpdated'] = ($result['type'] == 'tmp' || $this->User->isOuter() || in_array(
                $this->Table->getTableRow()['actual'],
                ['none', 'disable']
            )) ? 0 : 1;

        $result['isMain'] = true;
        if (!empty($a = ($request->getQueryParams()['a'] ?? null))) {
            $result['addVars'] = $a;
        }

        if ($this->User->isCreator()) {
            $result['TableFields'] = ['branchId' => 1, 'id' => 'tables_fields'];
            $result['Tables'] = ['branchId' => 1, 'id' => 'tables'];

            $result['hidden_fields'] = array_diff_key($this->Table->getFields(), $this->Table->getVisibleFields('web'));

            if ($result['tableRow']['type'] === 'calcs') {
                $result['TablesCyclesVersions'] = ['branchId' => 1, 'id' => 'calcstable_cycle_version'];
                $result['TablesVersions'] = ['branchId' => 1, 'id' => 'calcstable_versions'];
                $result['calcstable_cycle_version_filters'] = Crypt::getCrypted(json_encode(
                    [
                        'fl_table' => $this->Table->getTableRow()['tree_node_id'],
                        'fl_cycle' => $this->Table->getCycle()->getId()
                    ],
                    JSON_UNESCAPED_UNICODE
                ));
                $result['calcstable_versions_filters'] = Crypt::getCrypted(json_encode(
                    [
                        'fl_table' => $this->Table->getTableRow()['tree_node_id'],
                    ],
                    JSON_UNESCAPED_UNICODE
                ));
            }
            if (($types = $this->Totum->getCalculateLog()->getTypes())) {
                $result['LOGS'] = $this->CalculateLog->getLogsByElements($this->Table->getTableRow()['id']);
                $result['FullLOGS'] = [$this->CalculateLog->getLogsForjsTree()];
                 $result['treeLogs'] = $this->CalculateLog->getLodTree();
                if (in_array('flds', $types)) {
                    $result['FieldLOGS'] = $this->CalculateLog->getFieldLogs();
                }
            }
        }

        $this->__addAnswerVar('error', $error ?? $result['error'] ?? null);
        $this->__addAnswerVar('tableConfig', $result);
    }

    protected function checkTableByUri(ServerRequestInterface $request)
    {
        if (!preg_match('/^(\d+)\//', $this->tableUri, $branchMatches)) {
            return;
        }
        $this->branchId = $branchMatches[1];

        $tableUri = substr($this->tableUri, strlen($this->branchId) + 1);
        $tableId = 0;

        $checkTreeTable = function ($tableId) use ($request) {
            if (!array_key_exists($tableId, $this->User->getTables())) {
                throw new errorException('Доступ к таблице запрещен');
            } else {
                $this->onlyRead = $this->User->getTables()[$tableId] == 0;
                $extradata = null;
                if ($tableRow = $this->Config->getTableRow($tableId)) {
                    switch ($tableRow['type']) {
                        case 'calcs':
                            $this->__addAnswerVar('error', 'Неверный путь к таблице. Воспользуйтесь деревом');
                            return;
                        case 'tmp':
                            $extradata = $request->getParsedBody()['tableData']['sess_hash'] ?? $request->getQueryParams()['sess_hash'] ?? null;
                            break;
                    }
                    $this->Table = $this->Totum->getTable($tableRow, $extradata);
                }
            }
        };


        if (!empty($request->getParsedBody()['method']) && in_array($request->getParsedBody()['method'], ['getValue'])) {
            if (!empty($request->getParsedBody()['table_id'])) {
                $checkTreeTable((int)$request->getParsedBody()['table_id']);
                return;
            }
        }


        if ($tableUri && (preg_match(
                    '/^(\d+)\/(\d+)\/(\d+)/',
                    $tableUri,
                    $tableMatches
                ) || preg_match('/^(\d+)\/(\d+)/', $tableUri, $tableMatches))) {
            if (empty($tableMatches[3])) {
                $tableRow = $this->Config->getTableRow($tableMatches[2]);
                if ($tableRow['type'] !== 'calcs') {
                    throw new errorException('Ошибка строки доступа');
                }
                $tableId = $tableMatches[2];
                $tableMatches[2] = $tableMatches[1];
                $tableMatches[1] = $tableRow['tree_node_id'];
                $branchData = $this->Totum->getTable('tree')->getByParams(
                    ['field' => ['html', 'type', 'default_table', 'filters', 'top'], 'where' => [['field' => 'id', 'operator' => '=', 'value' => $this->branchId]]],
                    'row'
                );
                switch ($branchData['type']) {
                    case null:
                        $this->__addAnswerVar('html', $branchData['html']);
                        break;
                    case 'anchor':
                        $this->anchorId = $this->branchId;
                        $this->branchId = $branchData['top'];
                        break;
                }
            } else {
                $tableId = $tableMatches[3];
            }


            $this->Cycle = $this->Totum->getCycle($tableMatches[2], $tableMatches[1], $this->Totum);
            if (!$this->Cycle->loadRow()) {
                throw new errorException('Цикл не найден');
            }

            if (!array_key_exists($tableId, $this->User->getTables())) {
                throw new errorException('Доступ к таблице запрещен');
            } else {
                $this->onlyRead = $this->User->getTables()[$tableId] == 0;

                //Проверка доступа к циклу

                if (!$this->User->isCreator() && !empty($this->Cycle->getCyclesTable()->getFields()['creator_id']) && in_array(
                        $this->Cycle->getCyclesTable()->getTableRow()['cycles_access_type'],
                        [1, 2, 3]
                    )) {
                    //Если не связанный пользователь
                    if (count(array_intersect(
                            $this->Cycle->getRow()['creator_id']['v'],
                            $this->User->getConnectedUsers()
                        )) === 0) {
                        if ($this->Cycle->getCyclesTable()->getTableRow()['cycles_access_type'] == 3) {
                            $this->onlyRead = true;
                        } else {
                            throw new errorException('Доступ к циклу запрещен');
                            return;
                        }
                    }
                }

                if ($tableRow = $this->Config->getTableRow($tableId)) {
                    if ($tableRow['type'] != 'calcs') {
                        throw new errorException('Это не рассчетная таблица');
                    }
                    $this->Table = $this->Cycle->getTable($tableRow);
                }
            }
        } elseif ($tableUri && preg_match('/^([a-z0-9_]+)/', $tableUri, $tableMatches)) {
            if (!ctype_digit($tableMatches[1])) {
                $tableRow = $this->Config->getTableRow($tableMatches[1]);
                if ($tableRow) {
                    $tableId = $tableRow['id'];
                }
            } else {
                $tableId = $tableMatches[1];
            }
            $checkTreeTable($tableId);
        } else {
            $branchData = $this->Totum->getModel('tree')->executePrepared(
                false,
                ['id' => $this->branchId],
                'html,type,default_table,filters,top'
            )->fetch();

            switch ($branchData['type']) {
                case null:
                    $this->__addAnswerVar('html', $branchData['html']);
                    break;
                case 'anchor':
                    $this->anchorId = $this->branchId;
                    $this->branchId = $branchData['top'];
                    $this->Table = $this->Totum->getTable($branchData['default_table']);
                    $this->Table->setAnchorFilters($branchData['filters']);
                    break;
            }
        }

        if ($this->Table) {
            $this->CalculateLog = $this->Table->getCalculateLog();
            Conf::$CalcLogs = $this->CalculateLog;
        }
    }

    /**
     * @param ServerRequestInterface $request
     * @param string $method
     * @return AdminTableActions|ReadTableActions|WriteTableActions
     * @throws errorException
     */
    protected function getTableActions(ServerRequestInterface $request, string $method)
    {
        if (!$this->Table) {
            $Actions = new Actions($request, null, $this->Totum);
            $error = 'Таблица не найдена';
        } elseif ($this->User->isCreator()) {
            $Actions = new AdminTableActions($request, $this->Table);
            $error = 'Метод [[' . $method . ']] в этом модуле не определен';
        } elseif (!$this->onlyRead) {
            $Actions = new WriteTableActions($request, $this->Table);
            $error = 'Метод [[' . $method . ']] в этом модуле не определен или имеет админский уровень доступа';
        } else {
            $Actions = new ReadTableActions($request, $this->Table);
            $error = 'Ваш доступ к этой таблице - только на чтение. Обратитесь к администратору для внесения изменений';
        }

        if (!is_callable([$Actions, $method])) {
            throw new errorException($error);
        }
        return $Actions;
    }

    protected function clearTotum($request): void
    {
        $this->Config = $this->Config->getClearConf();
        $this->Totum = new Totum($this->Config, $this->User);
        $this->checkTableByUri($request);
    }
}
