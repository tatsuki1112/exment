<?php

namespace Exceedone\Exment\Database\Seeder;

use Exceedone\Exment\Enums;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\ConditionTypeDetail;
use Exceedone\Exment\Enums\CustomValueAutoShare;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Model;
use Exceedone\Exment\Model\ApiClientRepository;
use Exceedone\Exment\Model\Condition;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomFormPriority;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\CustomViewFilter;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\RoleGroupPermission;
use Exceedone\Exment\Model\RoleGroupUserOrganization;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Workflow;
use Illuminate\Database\Seeder;

class TestDataSeeder extends Seeder
{
    use TestDataTrait;

    /**
     * Run testdata.
     *
     * @return void
     */
    public function run()
    {
        $this->createSystem();

        $users = $this->createUserOrg();
       
        $menu = $this->createMenuParent();

        $custom_tables = $this->createTables($users, $menu);

        $this->createPermission($custom_tables);

        $this->createRelationTables($users);

        $this->createApiSetting();
    }

    protected function createSystem()
    {
        // create system data
        $systems = [
            'initialized' => true,
            'system_admin_users' => [1],
            'api_available' => true,
        ];
        foreach ($systems as $key => $value) {
            System::{$key}($value);
        }
    }

    protected function createUserOrg()
    {
        \Illuminate\Database\Eloquent\Relations\Relation::morphMap([
            'user' => ltrim(getModelName('user', true), "\\"),
            'organization' => ltrim(getModelName('organization', true), "\\"),
        ]);

        // set users
        $values = $this->getUsersAndOrgs();

        // set rolegroups
        $rolegroups = [
            'user' => [
                'user1' => [1], //data_admin_group
                'user2' => [4], //user_group
                'user3' => [4], //user_group
            ],
            'organization' => [
                'dev' => [4], //user_group
            ],
        ];

        $relationName = CustomRelation::getRelationNamebyTables('organization', 'user');

        foreach ($values as $type => $typevalue) {
            $custom_table = CustomTable::getEloquent($type);
            if (!isset($custom_table)) {
                continue;
            }
            
            foreach ($typevalue as $user_key => &$user) {
                $model = $custom_table->getValueModel();
                foreach ($user['value'] as $key => $value) {
                    $model->setValue($key, $value);
                }

                $model->save();

                if (array_has($user, 'password')) {
                    $loginUser = new LoginUser;
                    $loginUser->base_user_id = $model->id;
                    $loginUser->password = $user['password'];
                    $loginUser->save();
                }

                if (array_has($user, 'users')) {
                    $inserts = collect($user['users'])->map(function ($item) use ($model) {
                        return ['parent_id' => $model->id, 'child_id' => $item];
                    })->toArray();

                    \DB::table($relationName)->insert($inserts);
                }

                if (isset($rolegroups[$type][$user_key]) && is_array($rolegroups[$type][$user_key])) {
                    foreach ($rolegroups[$type][$user_key] as $rolegroup) {
                        $roleGroupUserOrg = new RoleGroupUserOrganization;
                        $roleGroupUserOrg->role_group_id = $rolegroup;
                        $roleGroupUserOrg->role_group_user_org_type = $type;
                        $roleGroupUserOrg->role_group_target_id = $model->id;
                        $roleGroupUserOrg->save();
                    }
                }
            }
        }

        return $values['user'];
    }

    protected function createRelationTables($users)
    {
        $menu = $this->createMenuParent([
            'title' => 'RelationTables',
        ]);

        $relations = [
            [
                'suffix' => '',
                'relation_type' => Enums\RelationType::ONE_TO_MANY,
            ],
            [
                'suffix' => '_n_n',
                'relation_type' => Enums\RelationType::MANY_TO_MANY,
            ],
            [
                'suffix' => '_select',
                'relation_type' => null,
            ],
        ];

        foreach ($relations as $relationItem) {
            // create parent
            $parentOptions = [
                'count' => ($relationItem['relation_type'] == 2 ? 10 : 1),
                'users' => $users,
                'menuParentId' => $menu->id,
            ];
            $parent_table = $this->createTable('parent_table' . $relationItem['suffix'], $parentOptions);
            $this->createPermission([Permission::CUSTOM_VALUE_EDIT_ALL => $parent_table]);
            
            $createRelationCallback = function ($custom_table) use ($parent_table, $relationItem) {
                if (isset($relationItem['relation_type'])) {
                    CustomRelation::create([
                        'parent_custom_table_id' => $parent_table->id,
                        'child_custom_table_id' => $custom_table->id,
                        'relation_type' => $relationItem['relation_type'],
                    ]);
                }
            };

            // create child
            $childOptions = [
                'users' => $users,
                'menuParentId' => $menu->id,
                'createColumnFirstCallback' => function ($custom_table, &$custom_columns) use ($parent_table, $relationItem) {
                    // set relation if select_table
                    if (!is_null($relationItem['relation_type'])) {
                        return;
                    }

                    // $parent_custom_value = $parent_table->getValueModel()->query()
                    //     ->where('value->text', "test_$i")
                    //     ->first();
                    // if(!isset($parent_custom_value)){
                    //     return;
                    // }

                    $options = [
                        'index_enabled' => 1,
                        'select_target_table' => $parent_table->id,
                    ];
                    $custom_column = CustomColumn::create([
                        'custom_table_id' => $custom_table->id,
                        'column_name' => 'parent_select_table',
                        'column_view_name' => 'parent_select_table',
                        'column_type' => ColumnType::SELECT_TABLE,
                        'options' => $options,
                    ]);
                    $custom_columns[] = $custom_column;
                },
                'createRelationCallback' => $createRelationCallback,
                'createValueSavingCallback' => function ($custom_value, $custom_table, $user, $i, $options) use ($parent_table, $relationItem) {
                    // set relation if 1:n
                    if ($relationItem['relation_type'] == Enums\RelationType::MANY_TO_MANY) {
                        return;
                    }

                    $parent_custom_value = $parent_table->getValueModel()->query()
                        ->where('value->text', "test_$i")
                        ->first();
                    if (!isset($parent_custom_value)) {
                        return;
                    }

                    if ($relationItem['relation_type'] == Enums\RelationType::ONE_TO_MANY) {
                        $custom_value->parent_id = $parent_custom_value->id;
                        $custom_value->parent_type = $parent_table->table_name;
                    } else {
                        $custom_value->setValue('parent_select_table', $parent_custom_value->id);
                    }
                },
                'createValueSavedCallback' => function ($custom_value, $custom_table, $user, $i, $options) use ($parent_table, $relationItem) {
                    // set relation if n:n
                    if ($relationItem['relation_type'] != Enums\RelationType::MANY_TO_MANY) {
                        return;
                    }

                    $parent_custom_value_ids = $parent_table->getValueModel()->query()
                        ->where('value->text', "test_$i")
                        ->get()
                        ->pluck('id');
                    if (empty($parent_custom_value_ids)) {
                        return;
                    }

                    $relationName = CustomRelation::getRelationNamebyTables($parent_table, $custom_table);

                    $parent_custom_value_ids->each(function ($parent_custom_value_id) use ($relationName, $custom_value, $custom_table) {
                        \DB::table($relationName)->insert([
                            'parent_id' => $parent_custom_value_id,
                            'child_id' => $custom_value->id,
                        ]);
                    });
                }
            ];
            $child_table = $this->createTable('child_table' . $relationItem['suffix'], $childOptions);

            $this->createPermission([Permission::CUSTOM_VALUE_EDIT_ALL => $child_table]);

            // cerate pivot table
            $pivot_table = $this->createTable('pivot_table' . $relationItem['suffix'], [
                'menuParentId' => $menu->id,
                'count' => 0,
                'createColumnCallback' => function ($custom_table, &$custom_columns) use ($parent_table, $child_table) {
                    // creating relation column
                    $columns = [
                        ['column_name' => 'child', 'column_type' => ColumnType::SELECT_TABLE, 'options' => ['index_enabled' => '1', 'select_target_table' => $child_table->id]],
                        ['column_name' => 'parent', 'column_type' => ColumnType::SELECT_TABLE, 'options' => ['index_enabled' => '1', 'select_target_table' => $parent_table->id]],
                        ['column_name' => 'child_relation_filter', 'column_type' => ColumnType::SELECT_TABLE, 'options' => ['index_enabled' => '1', 'select_target_table' => $child_table->id]],
                        ['column_name' => 'child_relation_filter_ajax', 'column_type' => ColumnType::SELECT_TABLE, 'options' => ['select_load_ajax' => 1, 'index_enabled' => '1', 'select_target_table' => $child_table->id]],
                        ['column_name' => 'parent_multi', 'column_type' => ColumnType::SELECT_TABLE, 'options' => ['index_enabled' => '1', 'multiple_enabled' => '1', 'select_target_table' => $parent_table->id]],
                        ['column_name' => 'child_relation_filter_multi', 'column_type' => ColumnType::SELECT_TABLE, 'options' => ['index_enabled' => '1', 'multiple_enabled' => '1', 'select_target_table' => $child_table->id]],
                        ['column_name' => 'child_relation_filter_multi_ajax', 'column_type' => ColumnType::SELECT_TABLE, 'options' => ['select_load_ajax' => 1, 'index_enabled' => '1', 'multiple_enabled' => '1', 'select_target_table' => $child_table->id]],
                    ];

                    foreach ($columns as $column) {
                        $custom_column = CustomColumn::create([
                            'custom_table_id' => $custom_table->id,
                            'column_name' => $column['column_name'],
                            'column_view_name' => $column['column_name'],
                            'column_type' => $column['column_type'],
                            'options' => $column['options'],
                        ]);
                        $custom_columns[] = $custom_column;
                    }
                },
                'createValueCallback' => function ($custom_value) use ($relationItem) {
                }
            ]);

            // select_relation
            $selectRelations = [
                ['parent' => 'parent', 'child' => 'child_relation_filter'],
                ['parent' => 'parent', 'child' => 'child_relation_filter_ajax'],
                ['parent' => 'parent_multi', 'child' => 'child_relation_filter_multi'],
                ['parent' => 'parent_multi', 'child' => 'child_relation_filter_multi_ajax'],
            ];

            foreach ($selectRelations as $selectRelation) {
                // append pivot table's relation filter
                $custom_forms = $pivot_table->custom_forms;
                foreach ($custom_forms as $custom_form) {
                    $custom_form_columns = $custom_form->custom_form_columns;
                    foreach ($custom_form_columns as $custom_form_column) {
                        if ($custom_form_column->form_column_type != Enums\FormColumnType::COLUMN) {
                            continue;
                        }

                        $form_custom_column = $custom_form_column->custom_column_cache;
                        if (!isset($form_custom_column)) {
                            continue;
                        }

                        if ($form_custom_column->column_name != $selectRelation['child']) {
                            continue;
                        }

                        // search 'parent' column
                        $parent_pivot_column = $pivot_table->custom_columns_cache->first(function ($custom_column) use ($selectRelation) {
                            return $custom_column->column_name == $selectRelation['parent'];
                        });
                        if (!isset($parent_pivot_column)) {
                            continue;
                        }

                        $custom_form_column->setOption('relation_filter_target_column_id', $parent_pivot_column->id);
                        $custom_form_column->save();
                    }
                }
            }
        }
    }

    protected function createMenuParent($options = [])
    {
        $options = array_merge(
            ['title' => 'TestTables'],
            $options
        );

        // create parent id
        $menu = Menu::create([
            'parent_id' => 0,
            'order' => 92,
            'title' => $options['title'],
            'icon' => 'fa-table',
            'uri' => '#',
            'menu_type' => 'parent_node',
            'menu_name' => $options['title'],
            'menu_target' => null,
        ]);

        return $menu;
    }

    protected function createTables($users, $menu)
    {
        // create test table
        $permissions = [
            Permission::CUSTOM_VALUE_EDIT_ALL,
            Permission::CUSTOM_VALUE_VIEW_ALL,
            Permission::CUSTOM_VALUE_ACCESS_ALL,
            Permission::CUSTOM_VALUE_EDIT,
            Permission::CUSTOM_VALUE_VIEW,
        ];

        $tables = [];
        foreach ($permissions as $permission) {
            $custom_table = $this->createTable($permission, [
                'users' => $users,
                'menuParentId' => $menu->id,
            ]);
            $tables[$permission] = $custom_table;
        }

        // create table for workflow
        foreach (range(1, 4) as $i) {
            $custom_table = $this->createTable("workflow$i", [
                'users' => $users,
                'menuParentId' => $menu->id,
                'customTableOptions' => ['all_user_editable_flg' => 1],
            ]);
        }

        // NO permission
        $this->createTable('no_permission', [
            'users' => $users,
            'menuParentId' => $menu->id,
        ]);

        return $tables;
    }

    /**
     * Create table
     *
     * @param [type] $keyName
     * @param [type] $users
     * @param [type] $menuParentId
     * @param array $customTableOptions
     * @return void
     */
    protected function createTable($keyName, $options = [])
    {
        extract(array_merge([
            'users' => [], // users who can has permission
            'menuParentId' => 0, // menu's parent id
            'customTableOptions' => [], // saving customtable option
            'createColumn' => true, // if false, not creating default columns
            'createColumnCallback' => null, // if not null, callback as creating columns instead of default
            'createColumnFirstCallback' => null, // if not null, callback as creating columns. After this callback, call default columns.
            'createRelationCallback' => null, // if not null, callback as creating relations
            'createValue' => true, // if false, not creating default values
            'createValueCallback' => null, // if not null, callback as creting value
        ], $options));

        $customTableOptions = array_merge([
            'search_enabled' => 1,
        ], $customTableOptions);

        // create table
        $custom_table = CustomTable::create([
            'table_name' => $keyName,
            'table_view_name' => $keyName,
            'options' => $customTableOptions,
        ]);

        System::clearRequestSession();
        \Illuminate\Database\Eloquent\Relations\Relation::morphMap([
            $keyName => ltrim(getModelName($custom_table, true), "\\")
        ]);

        $custom_columns = [];
        if ($createColumnCallback) {
            $createColumnCallback($custom_table, $custom_columns);
        } elseif ($createColumn) {
            if (isset($createColumnFirstCallback)) {
                $createColumnFirstCallback($custom_table, $custom_columns);
            }

            $columns = [
                ['column_name' => 'text', 'column_view_name' => 'text', 'column_type' => ColumnType::TEXT, 'options' => ['required' => '1']],
                ['column_name' => 'user', 'column_view_name' => 'user', 'column_type' => ColumnType::USER, 'options' => ['index_enabled' => '1']],
                ['column_name' => 'index_text', 'column_view_name' => 'index_text', 'column_type' => ColumnType::TEXT, 'options' => ['index_enabled' => '1'], 'label' => true],
                ['column_name' => 'odd_even', 'column_view_name' => 'odd_even', 'column_type' => ColumnType::TEXT, 'options' => ['index_enabled' => '1']],
                ['column_name' => 'multiples_of_3', 'column_view_name' => 'multiples_of_3', 'column_type' => ColumnType::YESNO, 'options' => ['index_enabled' => '1']],
                ['column_name' => 'file', 'column_view_name' => 'file', 'column_type' => ColumnType::FILE, 'options' => []],
                ['column_name' => 'date', 'column_view_name' => 'date', 'column_type' => ColumnType::DATE, 'options' => []],
                ['column_name' => 'init_text', 'column_view_name' => 'init_text', 'column_type' => ColumnType::TEXT, 'options' => ['init_only' => '1']],
            ];
    
            foreach ($columns as $column) {
                $custom_column = CustomColumn::create([
                    'custom_table_id' => $custom_table->id,
                    'column_name' => $column['column_name'],
                    'column_view_name' => $column['column_view_name'],
                    'column_type' => $column['column_type'],
                    'options' => $column['options'],
                ]);
        
                $custom_columns[] = $custom_column;

                if (boolval(array_get($column, 'label'))) {
                    Model\CustomColumnMulti::create([
                        'custom_table_id' => $custom_table->id,
                        'multisetting_type' => Enums\MultisettingType::TABLE_LABELS,
                        'options' => [
                            'table_label_id' => $custom_column->id,
                        ],
                    ]);
                }
            }
        }

        if (isset($createRelationCallback)) {
            $createRelationCallback($custom_table);
        }

        $custom_form_conditions = [
            [
                'condition_type' => ConditionType::CONDITION,
                'condition_key' => 1,
                'target_column_id' => ConditionTypeDetail::ORGANIZATION,
                'condition_value' => ["2"], // dev
            ],
            []
        ];

        foreach ($custom_form_conditions as $index => $condition) {
            // create form
            $custom_form = CustomForm::create([
                'custom_table_id' => $custom_table->id,
                'form_view_name' => ($index === 1 ? 'form_default' : 'form'),
                'default_flg' => ($index === 1),
            ]);
            CustomForm::getDefault($custom_table);
        
            if (count($condition) == 0) {
                continue;
            }
            
            $custom_form_priority = CustomFormPriority::create([
                'custom_form_id' => $custom_form->id,
                'order' => $index + 1,
            ]);

            $custom_form_condition = new Condition;
            $custom_form_condition->morph_type = 'custom_form_priority';
            $custom_form_condition->morph_id = $custom_form_priority->id;
            foreach ($condition as $k => $c) {
                $custom_form_condition->{$k} = $c;
            }
            $custom_form_condition->save();
        }

        $this->createView($custom_table, $custom_columns);

        $notify_id = $this->createNotify($custom_table);
        $options['notify_id'] = $notify_id;

        if (isset($createValueCallback)) {
            $options['custom_values'] = $createValueCallback($custom_table, $options);
        } elseif ($createValue) {
            $options['custom_values'] = $this->createValue($custom_table, $options);
        }

        // create table menu
        Menu::create([
            'parent_id' => $menuParentId,
            'order' => 0,
            'title' => $keyName,
            'icon' => 'fa-table',
            'uri' => $keyName,
            'menu_type' => 'table',
            'menu_name' => $keyName,
            'menu_target' => $custom_table->id,
        ]);

        return $custom_table;
    }

    protected function createPermission($custom_tables)
    {
        foreach ($custom_tables as $permission => $custom_table) {
            $roleGroupPermission = new RoleGroupPermission;
            $roleGroupPermission->role_group_id = 4;
            $roleGroupPermission->role_group_permission_type = 1;
            $roleGroupPermission->role_group_target_id = $custom_table->id;
            $roleGroupPermission->permissions = [$permission];
            $roleGroupPermission->save();
        }
    }

    protected function createValue($custom_table, $options = [])
    {
        extract(array_merge([
            'users' => [], // users who can has permission
            'count' => 10, // testdata count
            'notify_id' => null,
            'createValueSavingCallback' => null, // if not null, callback when saving value
            'createValueSavedCallback' => null, // if not null, callback when saved value
        ], $options));

        $custom_values = [];
        System::custom_value_save_autoshare(CustomValueAutoShare::USER_ORGANIZATION);
        foreach ($users as $key => $user) {
            \Auth::guard('admin')->attempt([
                'username' => $key,
                'password' => array_get($user, 'password')
            ]);

            $user_id = array_get($user, 'id');

            for ($i = 1; $i <= $count; $i++) {
                $custom_value = $custom_table->getValueModel();
                $custom_value->setValue("text", 'test_'.$user_id);
                $custom_value->setValue("user", $user_id);
                $custom_value->setValue("index_text", 'index_'.$user_id.'_'.$i);
                $custom_value->setValue("odd_even", ($i % 2 == 0 ? 'even' : 'odd'));
                $custom_value->setValue("multiples_of_3", ($i % 3 == 0 ? 1 : 0));
                $custom_value->setValue("date", \Carbon\Carbon::now());
                $custom_value->setValue("init_text", 'init_text');
                $custom_value->created_user_id = $user_id;
                $custom_value->updated_user_id = $user_id;

                if (isset($createValueSavingCallback)) {
                    $createValueSavingCallback($custom_value, $custom_table, $user, $i, $options);
                }

                $custom_value->save();

                if (isset($createValueSavedCallback)) {
                    $createValueSavedCallback($custom_value, $custom_table, $user, $i, $options);
                }

                if ($user_id == 3) {
                    // no notify for User2
                } elseif ($i == 5) {
                    $this->createNotifyNavbar($custom_table, $notify_id, $custom_value, 1);
                } elseif ($i == 10) {
                    $this->createNotifyNavbar($custom_table, $notify_id, $custom_value, 0);
                }

                $custom_values[] = $custom_value;
            }
        }

        return $custom_values;
    }
    
    /**
     * Create Notify
     *
     * @return void
     */
    protected function createNotify($custom_table)
    {
        $notify = new Notify;
        $notify->notify_view_name = $custom_table->table_name . '_notify';
        $notify->custom_table_id = $custom_table->id;
        $notify->notify_trigger = 2;
        $notify->action_settings = [
            "notify_action_target" => ["created_user"],
            "mail_template_id" => "6"];
        $notify->save();
        return $notify->id;
    }

    /**
     * Create View
     *
     * @return void
     */
    protected function createView($custom_table, $custom_columns)
    {
        ///// create AllData view
        $custom_view = new CustomView;
        $custom_view->custom_table_id = $custom_table->id;
        $custom_view->view_view_name = $custom_table->table_name . '-view-all';
        $custom_view->view_type = ViewType::SYSTEM;
        $custom_view->view_kind_type = ViewKindType::ALLDATA;
        $custom_view->save();
        $order = 1;
        
        $this->createSystemViewColumn($custom_view->id, $custom_table->id, $order++);

        foreach ($custom_columns as $custom_column) {
            $this->createViewColumn($custom_view->id, $custom_table->id, $custom_column->id, $order++);
        }
    
        // create andor
        foreach (['and', 'or'] as $join_type) {
            // create view
            $custom_view = new CustomView;
            $custom_view->custom_table_id = $custom_table->id;
            $custom_view->view_view_name = $custom_table->table_name . '-view-' . $join_type;
            $custom_view->view_type = ViewType::SYSTEM;
            $custom_view->view_kind_type = ViewKindType::DEFAULT;
            $custom_view->options = ['condition_join' => $join_type];
            $custom_view->save();
            $order = 1;

            $this->createSystemViewColumn($custom_view->id, $custom_table->id, $order++);

            foreach ($custom_columns as $custom_column) {
                $this->createViewColumn($custom_view->id, $custom_table->id, $custom_column->id, $order++);

                if ($custom_column->column_type == ColumnType::TEXT) {
                    if ($custom_column->column_view_name == 'odd_even') {
                        $this->createCustomViewFilter(
                            $custom_view->id,
                            ConditionType::COLUMN,
                            $custom_table->id,
                            $custom_column->id,
                            FilterOption::NE,
                            'odd'
                        );
                    }
                } elseif ($custom_column->column_type == ColumnType::YESNO) {
                    $this->createCustomViewFilter(
                        $custom_view->id,
                        ConditionType::COLUMN,
                        $custom_table->id,
                        $custom_column->id,
                        FilterOption::EQ,
                        1
                    );
                } elseif ($custom_column->column_type == ColumnType::USER) {
                    $this->createCustomViewFilter(
                        $custom_view->id,
                        ConditionType::COLUMN,
                        $custom_table->id,
                        $custom_column->id,
                        FilterOption::USER_EQ,
                        2
                    );
                }
            }
        }

        // workflow view
    }

    protected function createCustomViewFilter($custom_view_id, $view_column_type, $view_column_table_id, $view_column_target_id, $view_filter_condition, $view_filter_condition_value_text)
    {
        $custom_view_filter = new CustomViewFilter;
        $custom_view_filter->custom_view_id = $custom_view_id;
        $custom_view_filter->view_column_type = $view_column_type;
        $custom_view_filter->view_column_table_id = $view_column_table_id;
        $custom_view_filter->view_column_target_id = $view_column_target_id;
        $custom_view_filter->view_filter_condition = $view_filter_condition;
        $custom_view_filter->view_filter_condition_value_text = $view_filter_condition_value_text;
        $custom_view_filter->save();
    }
    
    protected function createSystemViewColumn($custom_view_id, $view_column_table_id, $order)
    {
        $custom_view_column = new CustomViewColumn;
        $custom_view_column->custom_view_id = $custom_view_id;
        $custom_view_column->view_column_type = ConditionType::SYSTEM;
        $custom_view_column->view_column_table_id = $view_column_table_id;
        $custom_view_column->view_column_target_id = 1;
        $custom_view_column->order = $order;
        $custom_view_column->save();
    }
    
    protected function createViewColumn($custom_view_id, $view_column_table_id, $view_column_target_id, $order)
    {
        $custom_view_column = new CustomViewColumn;
        $custom_view_column->custom_view_id = $custom_view_id;
        $custom_view_column->view_column_type = ConditionType::COLUMN;
        $custom_view_column->view_column_table_id = $view_column_table_id;
        $custom_view_column->view_column_target_id = $view_column_target_id;
        $custom_view_column->order = $order;
        $custom_view_column->save();
    }
    
    /**
     * Create Notify Navibar
     *
     * @return void
     */
    protected function createNotifyNavbar($custom_table, $notify_id, $custom_value, $read_flg)
    {
        $notify_navbar = new NotifyNavbar;
        $notify_navbar->notify_id = $notify_id;
        $notify_navbar->parent_type = $custom_table->table_name;
        $notify_navbar->parent_id = $custom_value->id;
        $notify_navbar->target_user_id = $custom_value->created_user_id;
        $notify_navbar->trigger_user_id = 1;
        $notify_navbar->read_flg = $read_flg;
        $notify_navbar->notify_subject = 'notify subject test';
        $notify_navbar->notify_body = 'notify body test';
        $notify_navbar->save();
    }
    
    protected function createApiSetting()
    {
        // init api
        $clientRepository = new ApiClientRepository;
        $client = $clientRepository->createPasswordGrantClient(
            1,
            Define::API_FEATURE_TEST,
            'http://localhost'
        );
        
        $clientRepository->createApiKey(
            1,
            Define::API_FEATURE_TEST_APIKEY,
            'http://localhost'
        );
    }
}