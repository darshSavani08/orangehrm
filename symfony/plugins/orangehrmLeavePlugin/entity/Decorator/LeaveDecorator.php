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

namespace OrangeHRM\Entity\Decorator;

use OrangeHRM\Core\Traits\ORM\EntityManagerHelperTrait;
use OrangeHRM\Entity\Employee;
use OrangeHRM\Entity\Leave;
use OrangeHRM\Entity\LeaveStatus;
use OrangeHRM\Entity\LeaveType;

class LeaveDecorator
{
    use EntityManagerHelperTrait;

    /**
     * @var Leave
     */
    private Leave $leave;

    /**
     * @param Leave $leave
     */
    public function __construct(Leave $leave)
    {
        $this->leave = $leave;
    }

    /**
     * @return Leave
     */
    protected function getLeave(): Leave
    {
        return $this->leave;
    }

    /**
     * @param int $empNumber
     */
    public function setEmployeeByEmpNumber(int $empNumber): void
    {
        /** @var Employee|null $employee */
        $employee = $this->getReference(Employee::class, $empNumber);
        $this->getLeave()->setEmployee($employee);
    }

    /**
     * @param int $id
     */
    public function setLeaveTypeById(int $id): void
    {
        /** @var LeaveType|null $leaveType */
        $leaveType = $this->getReference(LeaveType::class, $id);
        $this->getLeave()->setLeaveType($leaveType);
    }

    /**
     * @return string
     */
    public function getLeaveStatus(): string
    {
        /** @var LeaveStatus $leaveStatus */
        $leaveStatus = $this->getRepository(LeaveStatus::class)
            ->findOneBy(['status' => $this->getLeave()->getStatus()]);
        return ucfirst(strtolower($leaveStatus->getName()));
    }
}