<?php

namespace Victorlap\Approvable;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Class Approvable
 * @package Victorlap\Approvable
 */
trait Approvable
{

    /**
     * @var array
     */
    protected $approveOf = array();

    /**
     * @var array
     */
    protected $dontApproveOf = array();

    /**
     * Create the event listeners for the saving event
     * This lets us save approvals whenever a save is made, no matter the
     * http method
     */
    public static function bootApprovable()
    {
        static::saving(function ($model) {
            $model->preSave();
        });
    }

    /**
     * @return mixed
     */
    public function approvals()
    {
        return $this->morphMany('\Victorlap\Approvable\Approval', 'approvable');
    }

    /**
     * Generates a list of the last $limit approvals to any objects of the class it is being called from.
     * @param int $limit
     * @param string $order
     * @return mixed
     */
    public function classApprovals($limit = 100, $order = 'desc')
    {
        return Approval::where('approvable_type', get_called_class())
            ->orderBy('updated_at', $order)->limit($limit)->get();
    }

    /**
     * Invoked before a model is saved. Return false to abort the operation.
     * @return bool
     */
    public function preSave()
    {
        if ($this->currentUserCanApprove()) {
            // If the user is able to approve edits, do nothing.
            return true;
        }

        if (!$this->exists) {
            // There is currently no way (implemented) to enable this for new models.
            return true;
        }

        $changes_to_record = $this->changedApprovableFields();

        $approvals = array();
        foreach ($changes_to_record as $key => $change) {
            $approvals[] = array(
                'approvable_type' => $this->getMorphClass(),
                'approvable_id' => $this->getKey(),
                'key' => $key,
                'value' => $this->attributes[$key],
                'user_id' => $this->getSystemUserId(),
                'created_at' => new \DateTime(),
                'updated_at' => new \DateTime(),
            );
        }

        if (count($approvals) > 0) {
            $approval = new Approval();
            DB::table($approval->getTable())->insert($approvals);
        }

        return true;
    }

    /**
     * Get all of the changes that have been made, that are also supposed
     * to be approved.
     *
     * @return array fields with new data, that should be recorded
     */
    private function changedApprovableFields()
    {
        $dirty = $this->getDirty();
        $changes_to_record = array();

        foreach ($dirty as $key => $value) {
            if ($this->isApprovable($key)) {
                if (!isset($this->original[$key]) || $this->original[$key] != $this->attributes[$key]) {
                    $changes_to_record[$key] = $value;

                    // Reset changes that we want to approve
                    if (!isset($this->original[$key])) {
                        unset($this->attributes[$key]);
                    } else {
                        $this->attributes[$key] = $this->original[$key];
                    }
                }
            }
        }

        return $changes_to_record;
    }

    /**
     * @param $key
     * @return bool
     */
    private function isApprovable($key)
    {
        if (isset($this->approveOf) && in_array($key, $this->approveOf)) {
            return true;
        }
        if (isset($this->dontApproveOf) && in_array($key, $this->dontApproveOf)) {
            return false;
        }

        return empty($this->approveOf);
    }

    /**
     * @return mixed|null
     */
    protected function getSystemUserId()
    {
        if (Auth::check()) {
            return Auth::user()->getAuthIdentifier();
        }
        return null;
    }

    /**
     * @return bool
     */
    protected function currentUserCanApprove()
    {
        return Auth::check() && Auth::user()->can('approve', $this);
    }
}