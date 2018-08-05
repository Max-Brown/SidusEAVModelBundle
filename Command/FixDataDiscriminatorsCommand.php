<?php
/*
 * This file is part of the Sidus/EAVModelBundle package.
 *
 * Copyright (c) 2015-2018 Vincent Chalnot
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sidus\EAVModelBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Sidus\EAVModelBundle\Model\FamilyInterface;
use Sidus\EAVModelBundle\Registry\FamilyRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * See command description
 *
 * @author Vincent Chalnot <vincent@sidus.fr>
 */
class FixDataDiscriminatorsCommand extends Command
{
    /** @var FamilyRegistry */
    protected $familyRegistry;

    /** @var EntityManagerInterface */
    protected $entityManager;

    /**
     * @param FamilyRegistry         $familyRegistry
     * @param EntityManagerInterface $entityManager
     */
    public function __construct(FamilyRegistry $familyRegistry, EntityManagerInterface $entityManager)
    {
        $this->familyRegistry = $familyRegistry;
        $this->entityManager = $entityManager;
        parent::__construct();
    }

    /**
     * @throws \Symfony\Component\Console\Exception\InvalidArgumentException
     */
    protected function configure(): void
    {
        $description = 'Updates the database ensuring each data in the database has the proper Doctrine disciminator';
        $description .= 'corresponding to the data_class of the family in the model';
        $this
            ->setName('sidus:data:fix-discriminator')
            ->setDescription($description);
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @throws \UnexpectedValueException
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @throws \Doctrine\DBAL\DBALException
     *
     * @return int|null|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        foreach ($this->familyRegistry->getFamilies() as $family) {
            $this->updateFamilyData($family, $output);
        }
    }

    /**
     * @param FamilyInterface $family
     * @param OutputInterface $output
     *
     * @throws \UnexpectedValueException
     * @throws \Doctrine\DBAL\DBALException
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     */
    protected function updateFamilyData(FamilyInterface $family, OutputInterface $output): void
    {
        if (!$family->isInstantiable()) {
            return;
        }
        $metadata = $this->entityManager->getClassMetadata($family->getDataClass());
        if (!$metadata->discriminatorColumn) {
            return;
        }
        $sql = $this->generateSql(
            $metadata->getTableName(),
            $metadata->discriminatorColumn['fieldName'],
            $metadata->getColumnName('family')
        );

        $count = $this->updateTable($sql, $metadata->discriminatorValue, $family->getCode());
        if ($count) {
            $output->writeln("<comment>{$count} data updated for family {$family->getCode()}</comment>");
        } else {
            $output->writeln("<info>No data to clean for family {$family->getCode()}</info>");
        }
    }

    /**
     * @param string $table
     * @param string $discriminatorColumn
     * @param string $familyColumn
     *
     * @return string
     */
    protected function generateSql($table, $discriminatorColumn, $familyColumn): string
    {
        $sql = <<<EOS
UPDATE `{$table}`
    SET `{$discriminatorColumn}` = :discrValue
    WHERE
        `{$familyColumn}` = :familyCode AND `{$discriminatorColumn}` != :discrValue
EOS;

        return $sql;
    }

    /**
     * @param string $sql
     * @param string $discriminatorValue
     * @param string $familyCode
     *
     * @throws \RuntimeException
     * @throws \Doctrine\DBAL\DBALException
     *
     * @return int
     */
    protected function updateTable($sql, $discriminatorValue, $familyCode): int
    {
        $connection = $this->entityManager->getConnection();
        $stmt = $connection->prepare($sql);
        $stmt->bindValue(':discrValue', $discriminatorValue);
        $stmt->bindValue(':familyCode', $familyCode);

        if (!$stmt->execute()) {
            throw new \RuntimeException("Unable to run SQL statement {$sql}");
        }

        return $stmt->rowCount();
    }
}
