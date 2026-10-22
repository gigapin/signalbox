<?php

/** Define the persisted roles of a user within one team. */

namespace App\Enums\Identity;

/** Describe membership roles used by identity management and future resource policies. */
enum TeamRole: string
{
    /** Share ownership responsibilities, including the designated primary owner. */
    case Owner = 'owner';
    /** Manage member/viewer invitations and memberships; general resource policies remain M1.04. */
    case Admin = 'admin';
    /** Participate in team work subject to its authorization policies. */
    case Member = 'member';
    /** Read permitted team data without implying write access. */
    case Viewer = 'viewer';
}
