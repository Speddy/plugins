=head1 NAME

 Plugin::CronJobs

=cut

# i-MSCP Postgrey plugin
# Copyright (C) 2015 Laurent Declercq <l.declercq@nuxwin.com>
#
# This library is free software; you can redistribute it and/or
# modify it under the terms of the GNU Lesser General Public
# License as published by the Free Software Foundation; either
# version 2.1 of the License, or (at your option) any later version.
#
# This library is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
# Lesser General Public License for more details.
#
# You should have received a copy of the GNU Lesser General Public
# License along with this library; if not, write to the Free Software
# Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301 USA

package Plugin::Postgrey;

use strict;
use warnings;

use iMSCP::Debug;
use iMSCP::Execute;
use iMSCP::Service;
use parent 'Common::SingletonClass';

=head1 DESCRIPTION

 This package provide the backend part of the CronJobs plugin.

=head1 PUBLIC METHODS

=over 4

=item enable()

 Perform enable tasks

 Return 0 on success, other on failure

=cut

sub enable
{
	my $self = $_[0];

	my $rs = $self->_checkRequirements();
	return $rs if $rs;

	my ($stdout, $stderr);
	$rs = execute('postconf smtpd_recipient_restrictions', \$stdout, \$stderr);
	debug($stdout) if $stdout;
	error($stderr) if $stderr && $rs;
	return $rs if $rs;

	# Extract postconf values
	chomp($stdout);
	(my $postconfValues = $stdout) =~ s/^.*=\s*(.*)/$1/;

	my @smtpRestrictions = split ', ', $postconfValues;
	s/^permit$/check_policy_service inet:127.0.0.1:10023/ for @smtpRestrictions;
	push @smtpRestrictions, 'permit';

	my $postconf = 'smtpd_recipient_restrictions=' . escapeShell(join ', ', @smtpRestrictions);

	$rs = execute("postconf -e $postconf", \$stdout, \$stderr);
	debug($stdout) if $stdout;
	error($stderr) if $stderr && $rs;
	return $rs if $rs;

	# Make sure that postgrey daemon is running
	$rs = iMSCP::Service->getInstance()->restart('postgrey', '-f postgrey');
	return $rs if $rs;

	require Servers::mta;
	Servers::mta->factory()->{'restart'} = 1;

	0;
}

=item disable()

 Perform disable tasks

 Return 0 on success, other on failure

=cut

sub disable
{
	my ($stdout, $stderr);
	my $rs = execute('postconf smtpd_recipient_restrictions', \$stdout, \$stderr);
	debug($stdout) if $stdout;
	error($stderr) if $stderr && $rs;
	return $rs if $rs;

	# Extract postconf values
	chomp($stdout);
	(my $postconfValues = $stdout) =~ s/^.*=\s*(.*)/$1/;
	my @smtpRestrictions = grep { $_ !~ /^check_policy_service\s+inet:127.0.0.1:10023$/} split ', ', $postconfValues;

	my $postconf = 'smtpd_recipient_restrictions=' . escapeShell(join ', ', @smtpRestrictions);

	$rs = execute("postconf -e $postconf", \$stdout, \$stderr);
	debug($stdout) if $stdout;
	error($stderr) if $stderr && $rs;
	return $rs if $rs;

	require Servers::mta;
	Servers::mta->factory()->{'restart'} = 1;

	0;
}

=back

=head1 PRIVATE METHODS

=over 4

=item _init()

 Initialize instance

 Return Plugin::Postgrey or die on failure

=cut

sub _init
{
	my $self = $_[0];

	$self->{'FORCE_RETVAL'} = 'yes';

	$self;
}

=item _checkRequirements()

 Check for requirements

 Return int 0 if all requirements are meet, other otherwise

=cut

sub _checkRequirements
{
	my ($stdout, $stderr);
	my $rs = execute(
		"LANG=C dpkg-query --show --showformat '\${Status}' postgrey | cut -d ' ' -f 3", \$stdout, \$stderr
	);
	debug($stdout) if $stdout;
	if($stdout ne 'installed') {
		error("The postgrey package is not installed on your system");
		return 1;
	}

	0;
}

=back

=head1 AUTHOR

 Laurent Declercq <l.declercq@nuxwin.com>

=cut

1;
__END__