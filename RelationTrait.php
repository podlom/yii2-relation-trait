<?php

/**
 * RelationTrait
 *
 * @author Yohanes Candrajaya <moo.tensai@gmail.com>
 * @since 1.0
 */

namespace mootensai\relation;

use yii\db\ActiveQuery;
use \yii\db\ActiveRecord;
use \yii\db\Exception;
use \yii\helpers\Inflector;
use \yii\helpers\StringHelper;

trait RelationTrait {

    public function loadAll($POST) {
        if ($this->load($POST)) {
            $shortName = StringHelper::basename(get_class($this));
            foreach ($POST as $key => $value) {
                if ($key != $shortName && strpos($key, '_') === false) {
                    /* @var $rel ActiveQuery */
                    /* @var $this ActiveRecord */
                    /* @var $relObj ActiveRecord */
                    $isHasMany = is_array($value);
                    $relName = ($isHasMany) ? lcfirst(Inflector::pluralize($key)) : lcfirst($key);
                    $rel = $this->getRelation($relName);
                    $relModelClass = $rel->modelClass;
                    $relPKAttr = $relModelClass::primaryKey();
                    $isManyMany = count($relPKAttr) > 1;
                    if($isManyMany){
                        foreach($value as $relPost){
                            if(!empty(array_filter($relPost))){
                                $condition = [];
                                $condition[$this->primaryKey()[0]] = $this->primaryKey;
                                foreach($relPost as $relAttr => $relAttrVal){
                                    if(in_array($relAttr, $relPKAttr))
                                        $condition[$relAttr] = $relAttrVal;
                                }
                                $relObj = $relModelClass::findOne($condition);
                                if(is_null($relObj)){
                                    $relObj = new $relModelClass;
                                }
                                $relObj->load($relPost, '');
                                $container[] = $relObj;
                            }
                        }
                        $this->populateRelation($relName, $container);
                    }else if ($isHasMany) {
                        $container = [];
                        foreach ($value as $relPost) {
                            if(!empty(array_filter($relPost))) {
                                /* @var $relObj ActiveRecord */
                                $relObj = (empty($relPost[$relPKAttr[0]])) ? new $relModelClass : $relModelClass::findOne($relPost[$relPKAttr[0]]);
                                $relObj->load($relPost, '');
                                $container[] = $relObj;
                            }
                        }
                        $this->populateRelation($relName, $container);
                    } else {
                        $relObj = (empty($relPost[$relPKAttr[0]])) ? new $relModelClass : $relModelClass::findOne($relPost[$relPKAttr[0]]);
                        $relObj->load($value);
                        $this->populateRelation($relName, $relObj);
                    }
                }
            }
            return true;
        } else {
            return false;
        }
    }

    public function saveAll() {
        /* @var $this ActiveRecord */
        $db = $this->getDb();
        $trans = $db->beginTransaction();
        try {
            if ($this->save()) {
                $error = 0;
                if(!empty($this->relatedRecords)){
                    foreach ($this->relatedRecords as $name => $records) {
                        if(!empty($records)){
                            $AQ = $this->getRelation($name);
                            $link = $AQ->link;
                            $notDeletedPK = [];
                            $relPKAttr = $records[0]->primaryKey();
                            $isCompositePK = (count($relPKAttr) > 1);
                            /* @var $relModel ActiveRecord */
                            foreach ($records as $index => $relModel) {
                                $notDeletedFK = [];
                                foreach ($link as $key => $value) {
                                    $relModel->$key = $this->$value;
                                    $notDeletedFK[$key] = "$key = '{$this->$value}'";
                                }
                                $relSave = $relModel->save();
                                if (!$relSave || !empty($relModel->errors)) {
                                    $relModelWords = Inflector::camel2words(StringHelper::basename($AQ->modelClass));
                                    $index++;
                                    foreach ($relModel->errors as $validation) {
                                        foreach ($validation as $errorMsg) {
                                            $this->addError($name, "$relModelWords #$index : $errorMsg");
                                        }
                                    }
                                    $error = 1;
                                } else {
                                    //GET PK OF REL MODEL
                                    if ($isCompositePK) {
                                        foreach ($relModel->primaryKey as $attr => $value) {
                                            $notDeletedPK[$attr][] = "'$value'";
                                        }
                                    } else {
                                        $notDeletedPK[] = "'$relModel->primaryKey'";
                                    }
                                }
                            }
                            if (!$this->isNewRecord) {
                                //DELETE WITH 'NOT IN' PK MODEL & REL MODEL
                                $notDeletedFK = implode(' AND ', $notDeletedFK);
                                if ($isCompositePK) {
                                    echo "composite pk\n";
                                    $compiledNotDeletedPK = [];
                                    foreach ($notDeletedPK as $attr => $pks) {
                                        $compiledNotDeletedPK[$attr] = "$attr NOT IN(" . implode(', ', $pks) . ")";
                                        if (!empty($compiledNotDeletedPK[$attr])) {
                                            $relModel->deleteAll("$notDeletedFK AND " . implode(' AND ', $compiledNotDeletedPK));
                                        }
                                        print_r("$notDeletedFK AND " . implode(' AND ', $compiledNotDeletedPK)."\n");
                                    }
                                } else {
                                    $compiledNotDeletedPK = implode(',', $notDeletedPK);
                                    if (!empty($compiledNotDeletedPK)) {
                                        $relModel->deleteAll($notDeletedFK . ' AND ' . $relPKAttr[0] . " NOT IN ($compiledNotDeletedPK)");
                                    }
                                }
                            }
                        }
                    }
                }else{
                    if (!$this->isNewRecord) {
                        $relData = $this->getRelationData();
                        foreach($relData as $rel){
                            /* @var $relModel ActiveRecord */
                            $relModel = new $rel['modelClass'];
                            $condition = [];
                            foreach($rel['link'] as $k => $v){
                                $condition[] = $k . " = ". $this->$v;
                            }
                            $relModel->deleteAll(implode(" AND ",$condition));
                        }
                    }
                }

                if ($error) {
                    $trans->rollback();
                    return false;
                }
                $trans->commit();
                return true;
            } else {
                return false;
            }
        } catch (Exception $exc) {
            $trans->rollBack();
            throw $exc;
        }
    }

    public function deleteWithRelated() {
        /* @var $this ActiveRecord */
        $db = $this->getDb();
        $trans = $db->beginTransaction();
        try {
            $error = 0;
            $relData = $this->getRelationData();
            foreach ($relData as $data) {
                if($data['ismultiple']){
                    $link = $data['link'];
                    if(count($this->{$data['name']})){
                        $relPKAttr = $this->{$data['name']}[0]->primaryKey();
                        $isCompositePK = (count($relPKAttr) > 1);
                        foreach ($link as $key => $value) {
                            if(isset($this->$value)){
                                $array[$key] = $key . ' = ' . $this->$value;
                            }
                        }
                        $error = !$this->{$data['name']}[0]->deleteAll(implode(' AND ', $array));
                    }
                }
            }
            if ($error) {
                $trans->rollback();
                return false;
            }
            if($this->delete()){
                $trans->commit();
                return true;
            }
            $trans->rollBack();
        } catch (Exception $exc) {
            $trans->rollBack();
            throw $exc;
        }
    }



    public function getRelationData() {
        $ARMethods = get_class_methods('\yii\db\ActiveRecord');
        $modelMethods = get_class_methods('\yii\base\Model');
        $reflection = new \ReflectionClass($this);
        $i = 0;
        $stack = [];
        /* @var $method \ReflectionMethod */
        foreach ($reflection->getMethods() as $method) {
            if (in_array($method->name, $ARMethods) || in_array($method->name, $modelMethods)) {
                continue;
            }
            if($method->name === 'bindModels')  {continue;}
            if($method->name === 'attachBehaviorInternal')  {continue;}
            if($method->name === 'loadAll')  {continue;}
            if($method->name === 'saveAll')  {continue;}
            if($method->name === 'getRelationData')  {continue;}
            if($method->name === 'getAttributesWithRelatedAsPost')  {continue;}
            if($method->name === 'getAttributesWithRelated')  {continue;}
            if($method->name === 'deleteWithRelated')  {continue;}
            try {
                $rel = call_user_func(array($this,$method->name));
                if($rel instanceof \yii\db\ActiveQuery){
                    $stack[$i]['name'] = lcfirst(str_replace('get', '', $method->name));
                    $stack[$i]['method'] = $method->name;
                    $stack[$i]['ismultiple'] = $rel->multiple;
                    $stack[$i]['modelClass'] = $rel->modelClass;
                    $stack[$i]['link'] = $rel->link;
                    $i++;
                }
            } catch (\yii\base\ErrorException $exc) {}
        }
        return $stack;
    }

    /* this function is deprecated */

    public function getAttributesWithRelatedAsPost() {
        $return = [];
        $shortName = StringHelper::basename(get_class($this));
        $return[$shortName] = $this->attributes;
        foreach ($this->relatedRecords as $records) {
            foreach ($records as $index => $record) {
                $shortNameRel = StringHelper::basename(get_class($record));
                $return[$shortNameRel][$index] = $record->attributes;
            }
        }
        return $return;
    }

    public function getAttributesWithRelated() {
        $return = $this->attributes;
        foreach ($this->relatedRecords as $name => $records) {
            foreach ($records as $index => $record) {
                $return[$name][$index] = $record->attributes;
            }
        }
        return $return;
    }

}
