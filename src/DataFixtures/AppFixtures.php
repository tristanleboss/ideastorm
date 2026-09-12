<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Comment;
use App\Entity\Idea;
use App\Entity\Project;
use App\Entity\ProjectMembership;
use App\Entity\User;
use App\Entity\Vote;
use App\Enum\IdeaStatus;
use App\Enum\ProjectRole;
use App\Enum\ProjectStatus;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Demo data, so a fresh checkout has something to look at.
 *
 * Everybody shares the password "ideastorm" — these accounts exist to click around with,
 * never to deploy. Run with: php bin/console doctrine:fixtures:load
 */
class AppFixtures extends Fixture
{
    public function __construct(private readonly UserPasswordHasherInterface $passwordHasher)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $users = $this->createUsers($manager);
        $this->createSeaBreeze($manager, $users);
        $this->createLibrary($manager, $users);
        $this->createArchivedProject($manager, $users);

        $manager->flush();
    }

    /** @return array<string, User> */
    private function createUsers(ObjectManager $manager): array
    {
        $definitions = [
            'ada' => ['Ada Okonkwo', 'ada@example.com', ['ROLE_ADMIN']],
            'ben' => ['Ben Marchetti', 'ben@example.com', []],
            'chi' => ['Chidi Larsen', 'chidi@example.com', []],
            'dee' => ['Dee Nakamura', 'dee@example.com', []],
            'eli' => ['Eli Whitfield', 'eli@example.com', []],
            'fay' => ['Fay Oyelaran', 'fay@example.com', []],
        ];

        $users = [];
        foreach ($definitions as $key => [$name, $email, $roles]) {
            $user = new User();
            $user->setDisplayName($name);
            $user->setEmail($email);
            $user->setRoles($roles);
            $user->setPassword($this->passwordHasher->hashPassword($user, 'ideastorm'));

            $manager->persist($user);
            $users[$key] = $user;
        }

        return $users;
    }

    /** @param array<string, User> $users */
    private function createSeaBreeze(ObjectManager $manager, array $users): void
    {
        $project = $this->project(
            $manager,
            'Sea Breeze — mobile app',
            'sea-breeze',
            'Rebuilding the ferry booking app so a return trip takes under a minute to book.',
            "We are rewriting the booking flow from scratch. Everything about the journey — searching, picking seats, paying, and the ticket you show at the gate — is on the table.\n\nWhat we are after: fewer taps, an offline ticket, and a flow that works one-handed on a rocking boat.",
            '#0e7490',
            ProjectStatus::Open,
        );

        $this->assign($manager, $project, $users['ada'], ProjectRole::Owner, 'Accountable for the rewrite');
        $this->assign($manager, $project, $users['ben'], ProjectRole::Lead, 'Booking flow end to end');
        $this->assign($manager, $project, $users['chi'], ProjectRole::Contributor, 'Payments and refunds');
        $this->assign($manager, $project, $users['dee'], ProjectRole::Reviewer, 'Accessibility review');
        $this->assign($manager, $project, $users['fay'], ProjectRole::Observer);

        $offline = $this->idea(
            $manager,
            $project,
            $users['ben'],
            'Tickets should work with no signal at all',
            "Half the crossing has no reception, and people panic when the ticket screen spins.\n\nStore the ticket as soon as it is issued and render the barcode from local storage. The gate scanner does not need the network either — it can validate the signature offline and sync later.",
            IdeaStatus::Accepted,
        );

        $this->version(
            $manager,
            $project,
            $offline,
            $users['chi'],
            'Same idea, but ship it as a wallet pass',
            'Rather than building offline storage ourselves, issue an Apple Wallet / Google Wallet pass. The ticket is then handled by the OS, works offline for free, and lands on the lock screen at departure time. Less code for us, one fewer screen for the passenger.',
            'Wallet pass instead of in-app',
        );

        $this->version(
            $manager,
            $project,
            $offline,
            $users['dee'],
            'Offline ticket plus a printable fallback',
            'Keep the offline ticket, but also email a PDF with the same barcode. Not everyone trusts a phone battery on a two-hour crossing, and the crew already has paper scanners.',
            'With a paper fallback',
        );

        $seats = $this->idea(
            $manager,
            $project,
            $users['dee'],
            'Let people pick a seat from a deck map',
            'The current list of seat codes means nothing to anyone. Show the deck, mark the windows, the tables and the quiet zone, and let people tap the seat they want.',
            IdeaStatus::UnderReview,
        );

        $this->idea(
            $manager,
            $project,
            $users['fay'],
            'Remember the trip I take every week',
            'I book the same 07:40 crossing every Monday. Put it on the home screen as a one-tap rebook, with the return leg already filled in.',
            IdeaStatus::Proposed,
        );

        $this->idea(
            $manager,
            $project,
            $users['chi'],
            'Refunds without talking to anyone',
            'A cancelled crossing should refund itself. Today it takes an email and three days. If the operator cancels, refund automatically and tell the passenger it is done.',
            IdeaStatus::Shipped,
        );

        $this->comments($manager, $offline, [
            [$users['ada'], 'This is the one that matters most. Every complaint we get about the gate comes back to this.'],
            [$users['dee'], 'Agreed — and it needs to survive a phone rebooting mid-crossing, which local storage does and a cached web page does not.'],
            [$users['chi'], 'The wallet version below covers both without us writing storage code. Worth comparing properly before we commit.'],
        ]);

        $this->comments($manager, $seats, [
            [$users['ben'], 'We have the deck plans as SVG already from the safety cards.'],
            [$users['fay'], 'Please mark which seats have a plug. That is the only thing I care about on the early crossing.'],
        ]);

        $this->votes($manager, $offline, [$users['ada'], $users['chi'], $users['dee'], $users['fay']], []);
        $this->votes($manager, $seats, [$users['ada'], $users['ben'], $users['fay']], [$users['chi']]);
    }

    /** @param array<string, User> $users */
    private function createLibrary(ObjectManager $manager, array $users): void
    {
        $project = $this->project(
            $manager,
            'Neighbourhood tool library',
            'tool-library',
            'A shared shed of tools nobody needs to own: borrow a hedge trimmer instead of buying one.',
            "The shed exists, the tools are donated, and roughly forty households have keys. What we do not have is a sane way to know who has the good drill.\n\nIdeas welcome on lending, returns, repairs and how to keep it running without one person doing all the admin.",
            '#15803d',
            ProjectStatus::Open,
        );

        $this->assign($manager, $project, $users['fay'], ProjectRole::Owner, 'Runs the shed');
        $this->assign($manager, $project, $users['eli'], ProjectRole::Lead, 'Lending and returns');
        $this->assign($manager, $project, $users['ada'], ProjectRole::Contributor);

        $board = $this->idea(
            $manager,
            $project,
            $users['eli'],
            'A whiteboard beats an app',
            'Before anyone builds software: a magnetic whiteboard by the door with a tag per tool. Take the tag, write your name. We can see in a week whether the problem is tracking or just people forgetting.',
            IdeaStatus::UnderReview,
        );

        $this->version(
            $manager,
            $project,
            $board,
            $users['ada'],
            'Whiteboard now, photo log later',
            'Keep the whiteboard, but put a phone stand next to it and have people snap a photo of the board when they change it. Zero friction, and it gives us a history to look at if something goes missing.',
            'Whiteboard plus photo log',
        );

        $this->idea(
            $manager,
            $project,
            $users['fay'],
            'Monthly repair afternoon',
            'Half our tools are broken in small ways. One Saturday a month, whoever is handy fixes what is on the shelf, and everyone else brings cake.',
            IdeaStatus::Accepted,
        );

        $this->comments($manager, $board, [
            [$users['fay'], 'Strong yes. We tried a spreadsheet and it lasted eleven days.'],
            [$users['ada'], 'The photo log version costs nothing and answers "who had it last" — which is the actual question.'],
        ]);

        $this->votes($manager, $board, [$users['fay'], $users['ada']], []);
    }

    /** @param array<string, User> $users */
    private function createArchivedProject(ObjectManager $manager, array $users): void
    {
        $project = $this->project(
            $manager,
            'Café loyalty card',
            'cafe-loyalty',
            'A stamp card that lived on your phone. Wrapped up — kept here so the reasoning stays readable.',
            'We shipped it, ran it for a year, and closed it down when the café changed hands. The board stays open for reading.',
            '#b45309',
            ProjectStatus::Archived,
        );

        $this->assign($manager, $project, $users['chi'], ProjectRole::Owner);
        $this->assign($manager, $project, $users['ben'], ProjectRole::Contributor);

        $this->idea(
            $manager,
            $project,
            $users['ben'],
            'Stamps should transfer between branches',
            'People shop at whichever branch is closest to where they happen to be. Splitting the card per branch was the single biggest complaint we had.',
            IdeaStatus::Shipped,
        );
    }

    private function project(
        ObjectManager $manager,
        string $name,
        string $slug,
        string $summary,
        string $description,
        string $accent,
        ProjectStatus $status,
    ): Project {
        $project = new Project();
        $project->setName($name)
            ->setSlug($slug)
            ->setSummary($summary)
            ->setDescription($description)
            ->setAccentColor($accent)
            ->setStatus($status);

        $manager->persist($project);

        return $project;
    }

    private function assign(ObjectManager $manager, Project $project, User $user, ProjectRole $role, ?string $focus = null): void
    {
        $membership = new ProjectMembership($project, $user, $role);
        $membership->setFocus($focus);
        $project->addMembership($membership);

        $manager->persist($membership);
    }

    private function idea(ObjectManager $manager, Project $project, User $author, string $title, string $body, IdeaStatus $status): Idea
    {
        $idea = new Idea();
        $idea->setProject($project)
            ->setAuthor($author)
            ->setTitle($title)
            ->setBody($body)
            ->setStatus($status);

        $manager->persist($idea);

        return $idea;
    }

    private function version(ObjectManager $manager, Project $project, Idea $parent, User $author, string $title, string $body, string $label): Idea
    {
        $version = $this->idea($manager, $project, $author, $title, $body, IdeaStatus::Proposed);
        $version->setParent($parent);
        $version->setVersionLabel($label);
        $parent->addChild($version);

        return $version;
    }

    /** @param list<array{0: User, 1: string}> $entries */
    private function comments(ObjectManager $manager, Idea $idea, array $entries): void
    {
        foreach ($entries as [$author, $body]) {
            $comment = new Comment();
            $comment->setIdea($idea)->setAuthor($author)->setBody($body);
            $idea->addComment($comment);

            $manager->persist($comment);
        }
    }

    /**
     * @param list<User> $up
     * @param list<User> $down
     */
    private function votes(ObjectManager $manager, Idea $idea, array $up, array $down): void
    {
        foreach ($up as $user) {
            $manager->persist(new Vote($idea, $user, Vote::UP));
        }
        foreach ($down as $user) {
            $manager->persist(new Vote($idea, $user, Vote::DOWN));
        }
    }
}
