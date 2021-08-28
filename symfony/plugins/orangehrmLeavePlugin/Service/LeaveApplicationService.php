<?php
/**
 * OrangeHRM is a comprehensive Human Resource Management (HRM) System that captures
 * all the essential functionalities required for any enterprise.
 * Copyright (C) 2006 OrangeHRM Inc., http://www.orangehrm.com
 *
 * OrangeHRM is free software; you can redistribute it and/or modify it under the terms of
 * the GNU General Public License as published by the Free Software Foundation; either
 * version 2 of the License, or (at your option) any later version.
 *
 * OrangeHRM is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
 * without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program;
 * if not, write to the Free Software Foundation, Inc., 51 Franklin Street, Fifth Floor,
 * Boston, MA  02110-1301, USA
 */

namespace OrangeHRM\Leave\Service;

use DateTime;
use Exception;
use OrangeHRM\Core\Traits\Auth\AuthUserTrait;
use OrangeHRM\Core\Traits\ORM\EntityManagerHelperTrait;
use OrangeHRM\Entity\Employee;
use OrangeHRM\Entity\Leave;
use OrangeHRM\Entity\LeaveRequest;
use OrangeHRM\Entity\LeaveRequestComment;
use OrangeHRM\Entity\LeaveStatus;
use OrangeHRM\Entity\WorkflowStateMachine;
use OrangeHRM\Leave\Dto\LeaveParameterObject;
use OrangeHRM\Leave\Exception\LeaveAllocationServiceException;
use OrangeHRM\Leave\Traits\Service\LeaveEntitlementServiceTrait;

class LeaveApplicationService extends AbstractLeaveAllocationService
{
    use LeaveEntitlementServiceTrait;
    use EntityManagerHelperTrait;
    use AuthUserTrait;

    protected $dispatcher;
    protected $applyWorkflowItem = null;
    
    /**
     * Set dispatcher.
     * 
     * @param $dispatcher
     */
    public function setDispatcher($dispatcher) {
        $this->dispatcher = $dispatcher;
    }

    public function getDispatcher() {
        if(is_null($this->dispatcher)) {
            $this->dispatcher = sfContext::getInstance()->getEventDispatcher();
        }
        return $this->dispatcher;
    }

    /**
     * Creates a new leave application
     *
     * @param LeaveParameterObject $leaveAssignmentData
     * @return LeaveRequest|null
     * @throws LeaveAllocationServiceException When leave request length exceeds work shift length.
     */
    public function applyLeave(LeaveParameterObject $leaveAssignmentData): ?LeaveRequest
    {
        // TODO
//        if ($this->hasOverlapLeave($leaveAssignmentData)) {
//            throw new LeaveAllocationServiceException('Overlapping Leave Request Found');
//        }

        // TODO
//        if ($this->applyMoreThanAllowedForADay($leaveAssignmentData)) {
//            throw new LeaveAllocationServiceException('Work Shift Length Exceeded');
//        }

        return $this->saveLeaveRequest($leaveAssignmentData);
    }

    /**
     * Saves Leave Request and Sends Email Notification
     *
     * @param LeaveParameterObject $leaveAssignmentData
     * @return LeaveRequest|null True if leave request is saved else false
     * @throws LeaveAllocationServiceException
     *
     * @todo Don't catch general Exception. Catch specific one.
     */
    protected function saveLeaveRequest(LeaveParameterObject $leaveAssignmentData): ?LeaveRequest
    {
        $leaveRequest = $this->generateLeaveRequest($leaveAssignmentData);
        $leaveType = $this->getLeaveTypeService()->getLeaveTypeDao()->getLeaveTypeById(
            $leaveAssignmentData->getLeaveType()
        );
        $leaves = $this->createLeaveObjectListForAppliedRange($leaveAssignmentData);

        if ($this->isEmployeeAllowedToApply($leaveType)) {
            $nonHolidayLeaveDays = [];

            $holidayCount = 0;
            $holidays = [Leave::LEAVE_STATUS_LEAVE_WEEKEND, Leave::LEAVE_STATUS_LEAVE_HOLIDAY];
            foreach ($leaves as $k => $leave) {
                if (in_array($leave->getStatus(), $holidays)) {
                    $holidayCount++;
                } else {
                    $nonHolidayLeaveDays[] = $leave;
                }
            }

            if (count($nonHolidayLeaveDays) > 0) {
                $strategy = $this->getLeaveEntitlementService()->getLeaveEntitlementStrategy();
                $empNumber = $this->getAuthUser()->getEmpNumber();
                $entitlements = $strategy->handleLeaveCreate(
                    $empNumber,
                    $leaveType->getId(),
                    $nonHolidayLeaveDays,
                    false
                );

                if (!$this->allowToExceedLeaveBalance() && $entitlements == null) {
                    throw new LeaveAllocationServiceException('Leave Balance Exceeded');
                }
            }

            if ($holidayCount != count($leaves)) {
                try {
                    $loggedInUserId = $this->getAuthUser()->getUserId();
                    $loggedInEmpNumber = $this->getAuthUser()->getEmpNumber();

                    $leaveRequest = $this->getLeaveRequestService()
                        ->getLeaveRequestDao()
                        ->saveLeaveRequest($leaveRequest, $leaves, $entitlements);

                    if (!empty($leaveRequest->getComment())) {
                        $leaveRequestComment = new LeaveRequestComment();
                        $leaveRequestComment->setLeaveRequest($leaveRequest);
                        $leaveRequestComment->setCreatedAt(new DateTime());
                        $leaveRequestComment->getDecorator()->setCreatedByUserById($loggedInUserId);
                        $leaveRequestComment->getDecorator()->setCreatedByEmployeeByEmpNumber($loggedInEmpNumber);
                        $leaveRequestComment->setComment($leaveRequest->getComment());
                        $this->getLeaveRequestService()
                            ->getLeaveRequestDao()
                            ->saveLeaveRequestComment($leaveRequestComment);
                    }

                    //sending leave apply notification                   
//                    $workFlow = $this->getWorkflowItemForApplyAction($leaveAssignmentData);
//
//                    $employee = $this->getLoggedInEmployee();
//                    $eventData = [
//                        'request' => $leaveRequest, 'days' => $leaves, 'empNumber' => $employee->getEmpNumber(),
//                        'workFlow' => $workFlow
//                    ];
//                    $this->getDispatcher()->notify(new sfEvent($this, LeaveEvents::LEAVE_CHANGE, $eventData));

                    return $leaveRequest;
                } catch (Exception $e) {
                    $this->getLogger()->error('Exception while saving leave:' . $e->getMessage());
                    throw new LeaveAllocationServiceException('Leave Quota will Exceed');
                }
            } else {
                throw new LeaveAllocationServiceException('No working days in leave request');
            }
        }

        return null;
    }

    /**
     * Returns leave status based on weekend and holiday
     *
     * If weekend, returns Leave::LEAVE_STATUS_LEAVE_WEEKEND
     * If holiday, returns Leave::LEAVE_STATUS_LEAVE_HOLIDAY
     * Else, returns Leave::LEAVE_STATUS_LEAVE_PENDING_APPROVAL
     *
     * @inheritDoc
     */
    public function getLeaveRequestStatus(
        bool $isWeekend,
        bool $isHoliday,
        DateTime $leaveDate,
        LeaveParameterObject $leaveAssignmentData
    ): int {
        $status = null;

        if ($isWeekend) {
            $status = Leave::LEAVE_STATUS_LEAVE_WEEKEND;
        }

        if ($isHoliday) {
            $status = Leave::LEAVE_STATUS_LEAVE_HOLIDAY;
        }

        if (is_null($status)) {
            $workFlowItem = $this->getWorkflowItemForApplyAction($leaveAssignmentData);
            $status = Leave::LEAVE_STATUS_LEAVE_PENDING_APPROVAL;
            if ($workFlowItem instanceof WorkflowStateMachine) {
                /** @var LeaveStatus|null $leaveStatus */
                $leaveStatus = $this->getRepository(LeaveStatus::class)->findOneBy(
                    ['name' => $workFlowItem->getResultingState()]
                );
                if ($leaveStatus instanceof LeaveStatus) {
                    $status = $leaveStatus->getStatus();
                }
            }
        }

        return $status;
    }

    /**
     * @inheritDoc
     */
    protected function allowToExceedLeaveBalance(): bool
    {
        return false;
    }

    /**
     * @param LeaveParameterObject $leaveAssignmentData
     * @return WorkflowStateMachine|null
     */
    protected function getWorkflowItemForApplyAction(LeaveParameterObject $leaveAssignmentData): ?WorkflowStateMachine
    {
        if (is_null($this->applyWorkflowItem)) {
            $empNumber = $leaveAssignmentData->getEmployeeNumber();
            $workFlowItems = $this->getUserRoleManager()
                ->getAllowedActions(
                    WorkflowStateMachine::FLOW_LEAVE,
                    'INITIAL', [],
                    [],
                    [Employee::class => $empNumber]
                );

            // get apply action
            foreach ($workFlowItems as $item) {
                if ($item->getAction() == 'APPLY') {
                    $this->applyWorkflowItem = $item;
                    break;
                }
            }
        }

        if (is_null($this->applyWorkflowItem)) {
            $this->getLogger()->error("No workflow item found for APPLY leave action!");
        }

        return $this->applyWorkflowItem;
    }

    /**
     * Is Valid leave request
     * @param LeaveType $leaveType
     * @param array $leaveRecords
     * @returns boolean
     */
    protected function isValidLeaveRequest($leaveRequest, $leaveRecords) {
        $holidayCount = 0;
        $requestedLeaveDays = [];
        $holidays = [Leave::LEAVE_STATUS_LEAVE_WEEKEND, Leave::LEAVE_STATUS_LEAVE_HOLIDAY];
        foreach ($leaveRecords as $k => $leave) {
            if (in_array($leave->getStatus(), $holidays)) {
                $holidayCount++;
            }
//            $leavePeriod = $this->getLeavePeriodService()->getLeavePeriod(strtotime($leave->getLeaveDate()));
//            if($leavePeriod instanceof LeavePeriod) {
//                $leavePeriodId = $leavePeriod->getLeavePeriodId();
//            } else {
//                $leavePeriodId = null; //todo create leave period?
//            }
//
//            if(key_exists($leavePeriodId, $requestedLeaveDays)) {
//                $requestedLeaveDays[$leavePeriodId] += $leave->getLeaveLengthDays();
//            } else {
//                $requestedLeaveDays[$leavePeriodId] = $leave->getLeaveLengthDays();
//            }
        }

        //if ($this->isLeaveRequestNotExceededLeaveBalance($requestedLeaveDays, $leaveRequest) && $this->hasWorkingDays($holidayCount, $leaveRecords)) {
            return true;
        //}
    }
    
    /**
     * isLeaveRequestNotExceededLeaveBalance
     * @param array $requestedLeaveDays key => leave period id
     * @param LeaveRequest $leaveRequest
     * @returns boolean
     */
    protected function isLeaveRequestNotExceededLeaveBalance($requestedLeaveDays, $leaveRequest) {

        if (!$this->getLeaveEntitlementService()->isLeaveRequestNotExceededLeaveBalance($requestedLeaveDays, $leaveRequest)) {
            throw new LeaveAllocationServiceException('Failed to Submit: Leave Balance Exceeded');
            return false;
        }
        return true;
    }
    
    /**
     * hasWorkingDays
     * @param LeaveType $leaveType
     * @returns boolean
     */
    protected function hasWorkingDays($holidayCount, $leaves) {

        if ($holidayCount == count($leaves)) {
            throw new LeaveAllocationServiceException('Failed to Submit: No Working Days Selected');
        }

        return true;
    }

    /**
     * @deprecated
     * @return Employee
     * @todo Remove the use of session
     */
    public function getLoggedInEmployee() {
        $employee = $this->getEmployeeService()->getEmployee($_SESSION['empNumber']);
        return $employee;
    }
}