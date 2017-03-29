<?php
namespace Yummy\Controller\Component;

use Cake\Controller\Component;
use Cake\Datasource\ConnectionManager;
use Cake\Utility\Inflector;

/**
 * This component is a should be used in conjunction with the YummySearchHelper for building rudimentary search filters
 */
class YummySearchComponent extends Component
{

    public function startup(){
        $this->controller = $this->_registry->getController();
    }
    
    /**
     * beforeRender - sets fields for use by YummySearchHelper
     */
    public function beforeRender()
    {
        $database = ConnectionManager::get('default');

        // check components
        $this->checkComponents();
        
        // Create a schema collection.
        $this->collection = $database->schemaCollection();

        // merge configurations
        $this->mergeConfig();

        // set array for use by YummySearchHelper
        $yummy = $this->getYummyHelperData();

        // make yummy search data available to view
        $this->controller->set('YummySearch', $yummy);
    }

    /**
     * checkComponents - throws exception if missing a required component
     * @throws InternalErrorException
     */
    private function checkComponents()
    {
        if (!isset($this->controller->Paginator)) {
            throw new InternalErrorException(__('YummySearch requires the Paginator Component'));
        }
    }
    
    /**
     * mergeConfig - merges user supplied configuration with defaults
     * @return void
     */
    private function mergeConfig()
    {

        if ($this->config('operators') != null) {
            return;
        }

        $config = [
            'operators' => [
                'containing' => 'Containing',
                'not_containing' => 'Not Containing',
                'greater_than' => 'Greater than',
                'less_than' => 'Less than',
                'matching' => 'Exact Match',
                'not_matching' => 'Not Exact Match',
            ]
        ];

        $this->configShallow($config);
    }

    /**
     * getYummyHelperData - retrieves an array used by YummySearchHelper
     * @return array
     */
    private function getYummyHelperData()
    {
        $yummy = [
            'base_url' => $this->controller->request->here,
            'rows' => $this->controller->request->query('YummySearch'),
            'operators' => $this->config('operators'),
            'models' => $this->getModels()
        ];
        
        return $yummy;
    }
    
    /**
     * getColumns - returns array of columns after checking allow/deny rules
     * @param string $name
     * @return array
     * [ModelName.column_name => Column Name]
     */
    private function getColumns($name)
    {
        $data = [];
        $tableName = Inflector::underscore($name);
        $modelName = Inflector::classify($tableName);
        $schema = $this->collection->describe($tableName);
        $columns = $schema->columns();
        
        foreach($columns as $column){
            if( $this->isColumnAllowed($modelName, $column) == true ){
                $data["$modelName.$column"] = Inflector::humanize($column);
            }
        }
        
        return $data;
    }
    
    /**
     * getModels - returns an array of models and their columns
     * @return array
     * [ModelName => [ModelName.column_name => Column Name]]
     */
    private function getModels()
    {
        // gets array of Cake\ORM\Association objects
        
        $thisModel = $this->config('model');
        
        $associations = $this->controller->{$thisModel}->associations();

        $models = ["$thisModel" => $this->getColumns($thisModel)];
        
        $allowedAssociations = ['Cake\ORM\Association\HasOne', 'Cake\ORM\Association\BelongsTo'];
        
        foreach($associations as $object){
            
            $name = Inflector::humanize(Inflector::tableize($object->getName()));
            $table = $object->getTable();

            if( !isset($models[ $name ]) && in_array(get_class($object), $allowedAssociations) ){
                $columns = $this->getColumns($table);
                if( !empty($columns) ){
                    $models[ $name ] = $columns;
                }
            }
        }
        
        return $models;
    }

    /**
     * isColumnAllowed - checks allow/deny rules to see if column is allowed
     * @param string $model
     * @param string $column
     * @return boolean
     */
    private function isColumnAllowed($model, $column){
        
        $config = $this->config();
        
        if( isset($config['deny'][$model][$column]) ){
            return false;
        } else if( isset($config['deny'][$model]) && $config['deny'][$model] == '*' ){
            return false;
        } else if( isset($config['allow'][$model]) && !in_array($column, $config['allow'][$model]) ) {
            return false;
        }
        return true;
    }
    
    /**
     * getSqlCondition - returns cakephp orm compatible condition after checking allow/deny rules
     * @param string $model
     * @param string $column
     * @param string $operator
     * @param string $value
     * @return array|bool: array on success, false if operator is not found
     */
    private function getSqlCondition($model, $column, $operator, $value)
    {
        if ($this->isColumnAllowed($model, $column) == false) {
            return false;
        }
        
        switch ($operator) {
            case 'matching':
                return [$model.$column => $value];
            case 'not_matching';
                return ["$model.$column != " => $value];
            case 'containing';
                return ["$model.$column LIKE " => "%$value%"];
            case 'not_containing';
                return ["$model.$column NOT LIKE " => "%$value%"];
            case 'greater_than';
                return ["$model.$column > " => "%$value%"];
            case 'less_than';
                return ["$model.$column < " => "%$value%"];
        }
        return false;
    }

    /**
     * search - appends cakephp orm conditions to PaginatorComponent
     * @return bool: true if search query was requested, false if not
     */
    public function search()
    {
        // exit if no search was performed or user cleared search paramaters
        $this->controller = $this->_registry->getController();
        $request = $this->controller->request;
        if ($request->query('YummySearch') == null || $request->query('YummySearch_clear') != null) {
            return false;
        }

        $data = $request->query('YummySearch');     // get query parameters
        $length = count($data['field']);            // get array length

        if( !isset($this->controller->paginate['conditions']) ){
            $this->controller->paginate['conditions'] = [];
        }

        // loop through available fields and set conditions
        for ($i = 0; $i < $length; $i++) {
            $field = $data['field'][$i];            // get field name
            $operator = $data['operator'][$i];      // get operator type
            $search = $data['search'][$i];          // get search paramter
            
            list($model, $column) = explode('.', $field);
            
            $conditions = $this->getSqlCondition($model, $column, $operator, $search);

            if( is_array($conditions) ){
                $this->controller->paginate['conditions'] = array_merge(
                    $this->controller->paginate['conditions'], 
                    $conditions
                );
            }
        }
        
        return true;
    }
}