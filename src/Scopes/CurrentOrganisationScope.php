<?php
namespace MultiSiteRouter\Scopes;

use Illuminate\Database\Eloquent\ScopeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;

class CurrentOrganisationScope implements ScopeInterface
{

    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $builder
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function apply(Builder $builder, Model $model)
    {
        if (Config::get('multisite.organisation_id')) {
            if ($model->getTable() == 'organisation') {
                $field_name = 'id';
            } else {
                $field_name = 'organisation_id';
            }
            $builder->where($field_name, '=', Config::get('multisite.organisation_id'));
        }
    }

    /**
     * Remove the scope from the given Eloquent query builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $builder
     * @param  \Illuminate\Database\Eloquent\Model  $model
     *
     * @return void
     */
    public function remove(Builder $builder, Model $model) {
        if (Config::get('multisite.organisation_id')) {
            $query = $builder->getQuery();

            if ($model->getTable() == 'organisation') {
                $field_name = 'id';
            } else {
                $field_name = 'organisation_id';
            }

            if(isset($where[$field_name]) && $where[$field_name] == Config::get('multisite.organisation_id')) {
                unset($query->wheres[$field_name]);
                $query->wheres = array_values($query->wheres);
            }
        }
    }
}
